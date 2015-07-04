<?php

require_once($_SERVER['DOCUMENT_ROOT']."/system/global.inc.php");


class CloudAPI {

    /**
     * Return the current user that is using the system
     * Example: {"id":1,"name":"anonymous","admin":false}
     * @echo A JSON String with the current user
     */
    static function getCurrentUser() {
        echo System::getCurrentUser()->get_json();
    }

    /**
     * Return the requested file
     * @param int $id (int)
     *          The ID of the file to be returned
     * @param string[] $options
     *          A array with options to be passed
     *          $options['width'] -> if image: the wished width of the returned image "400" or "100". Return the original image if size is not available
     * @echo The requested file
     */
    static function getFile($id, $options = []) {
        $mysqli = System::connect('cloud');

        // Extend options
        $default_options = array("width" => 0);
        $options = array_merge($default_options, $options);

        // Escape values
        $id = $mysqli->real_escape_string($id);

        // Get file if user has access
        if(Util::has_read_access($id)) { // allowed

            // user has access -> get file data
            $sql = "SELECT files.id, files.title, files.type, files.filename, files.folder
                FROM files
                WHERE files.id = ".$id;
            $file = $mysqli->query($sql)->fetch_assoc();

            // return file
            $attachment_location = './uploads/'.$file['id'].'/'.$file['filename'];

            // handle options
            if(exif_imagetype($attachment_location) != false) { // Images can be found in scaled versions
                if($options['width'] > 0) {
                    $new_attachment_location = './uploads/'.$file['id'].'/'.$options['width'].'width_'.$file['filename'];
                    if(file_exists($new_attachment_location)) $attachment_location = $new_attachment_location;
                }
            }

            // read file
            if (file_exists($attachment_location)) {
                header($_SERVER["SERVER_PROTOCOL"] . " 200 OK");
                header("Cache-Control: public"); // needed for i.e.
                header("Content-Type: ".$file['type']);
                header("Content-Transfer-Encoding: Binary");
                header("Content-Length:".filesize($attachment_location));
                header("Content-Disposition: attachment; filename=".$file['filename']);
                header("File-Title: ".$file['title']);
                header("File-ID: ".$file['id']);
                header("File-Folder: ".$file['folder']);
                readfile($attachment_location);
                die();
            } else { // file was not found on the server
                header('HTTP/1.0 503 Service Unavailable');
                echo "Service Unavailable";
                die;
            }
        }

        // not found or no right set for user-file connection
        header('HTTP/1.0 404 Not found');
        echo "Not found";
        exit;
    }

    /**
     * Save the passed file.
     * If ID is set the current file becomes updated if write access for current user
     * If no ID is passed a new file becomes created
     * @param $file
     *          The file to be stored
     * @param null $id
     *          The ID of the file
     * @param string $title
     *          The new title of the file. Only available when initializing a new file.
     *          Use setFileAttributes to update an existing file
     * @param int $folder
     *          The ID of the folder where the file should be registered
     * @echo int
     *          The final ID of the file
     */
    static function setFile($file, $id = null, $title = '', $folder = null) {
        $mysqli = System::connect('cloud');
        $current_user = System::getCurrentUser();

        if($folder == null) $folder = 1;

        if(is_array($file)) {
            $image_type = exif_imagetype($file['tmp_name']);
            $type = $mysqli->real_escape_string($file['type']);
            $filename = Util::get_filename_for($file['name']);
        } else {
            list($width, $height, $image_type, $attr) = getimagesize($file);
            $type = image_type_to_mime_type($image_type);
            $filename = basename($file);
            $arr_filename = explode(".", $filename);
            if(count($arr_filename) > 0) $filename = $arr_filename[0];
            $filename = Util::get_filename_for($filename.image_type_to_extension($image_type));
            if($title == '') $title = $filename;
        }

        if($id == null || $id == '') { // new file
            // Register file in DB, set rights and create folder
            $id = Util::prepareForNewFile($title, $type, $filename, $folder, $current_user->id);

        } else { // update existing file

            // Check write access
            if(!Util::has_write_access($id, 'file')) {
                header('HTTP/1.0 403 Forbidden');
                echo (new ErrorMessage(403, 'Forbidden', 'You do not have write access to the requested file-ID.'));
                die;
            }

            // Delete actual files
            $existing_files = glob(Util::$uploadDir."/$id/*"); // get all file names
            foreach($existing_files as $deletable_file){ // iterate files
                if(is_file($deletable_file)) unlink($deletable_file); // delete file
            }

        }

        // Save original file
        if(is_array($file)) $success = move_uploaded_file($file['tmp_name'], Util::$uploadDir."/$id/$filename");
        else $success = copy($file, Util::$uploadDir."/$id/$filename");

        // save scaled versions if it is an image
        // $image_type is false if this is not an image
        if($image_type && ($image_type == IMAGETYPE_JPEG || $image_type == IMAGETYPE_GIF || $image_type == IMAGETYPE_PNG)) {
            include_once("lib/SimpleImage.php");
            $image = new SimpleImage();
            $image->load(Util::$uploadDir."/$id/$filename");
            $image->resizeToWidth(400);
            $image->save(Util::$uploadDir."/$id/400width_$filename");
            $image->resizeToWidth(100);
            $image->save(Util::$uploadDir."/$id/100width_$filename");
        }

        if($success) {
            header('HTTP/1.0 200 OK');
            echo json_encode(array('id' => $id));
        } else {
            header('HTTP/1.0 400 Internal Server Error');
            echo new ErrorMessage(400, 'Internal Server Error', 'Your uploaded file could not be saved.');
        }

        // allow hot-link for public files
        Util::set_chmod($id, Util::has_read_access($id, 'file', User::get_default_user()));

    }

    /**
     * Deletes the file with the passed ID from Database and FileSystem
     * @param int $id
     */
    static function deleteFile($id) {
        $mysqli = System::connect('cloud');

        // Escape values
        $id = $mysqli->real_escape_string($id);

        // Get file if user has access
        if(Util::has_admin_access($id)) { // allowed
            // Delete access rights
            $sql = "DELETE FROM rights WHERE data_type LIKE 'file' AND data_id = $id";
            $mysqli->query($sql);

            // Delete file from Database
            $sql = "DELETE FROM files WHERE id = $id";
            $mysqli->query($sql);

            // Delete file from File System
            $existing_files = glob(Util::$uploadDir."/$id/*"); // get all file names
            foreach($existing_files as $deletable_file){ // iterate files
                if(is_file($deletable_file)) unlink($deletable_file); // delete file
            }
            rmdir(Util::$uploadDir."/$id");

            // Output
            header('HTTP/1.0 200 OK');
            echo json_encode(array('id' => $id));
            die;
        }

        // not found or no admin access
        header('HTTP/1.0 403 Forbidden');
        echo new ErrorMessage(403, 'Forbidden', 'You have no admin access to the requested file.');
        exit;
    }

    /**
     * Return a JSON String with the attributes of the file with the passed ID
     * @param int $id
     *          The ID of the file
     * @echo string
     *          A JSON string with attributes
     */
    static function getFileAttributes($id) {
        $mysqli = System::connect('cloud');

        // Escape values
        $id = $mysqli->real_escape_string($id);

        // check read access
        if(!Util::has_read_access('file', $id)) {
            header('HTTP/1.0 403 Forbidden');
            echo new ErrorMessage(403, 'Forbidden', 'You have no read access to the requested file.');
            exit;
        }

        // get data
        $sql = "SELECT files.id, files.title, files.type, files.filename, files.folder
                FROM files
                WHERE files.id = $id";
        $data = [];
        if($result = $mysqli->query($sql)) {
            while($r = $result->fetch_assoc()) {
                $r['data_type'] = 'file';
                $r['hotlink'] = 'http://cloud.salzhimmel.de/uploads/'.$r['id'].'/'.$r['filename'];
                $data[] = $r;
            }
        }

        //Print result
        echo json_encode($data);
    }

    /**
     * Saves the attributes to the passed file
     * @param int $id
     *          The ID of the file to be updated
     * @param string[] $attributes
     *          The attributes of the file
     *          $attributes['title']
     *          $attributes['folder']
     * @echo string
     *          A JSON String with whether the update was accepted
     */
    static function setFileAttributes($id, $attributes) {
        $mysqli = System::connect('cloud');

        // Escape values
        $id = $mysqli->real_escape_string($id);

        // check write access
        if(!Util::has_write_access($id, 'file')) {
            header('HTTP/1.0 403 Forbidden');
            echo new ErrorMessage(403, 'Forbidden', 'You have no write access to the requested file.');
            exit;
        }

        // filter attributes
        $accepted_updates = array();
        foreach($attributes as $key => $value) {
            if($key == 'title' || $key == 'folder') {
                $accepted_updates[] = "$key = '".$mysqli->real_escape_string($value)."'";
            }
        }

        // check if something has to be updated
        if(count($accepted_updates) < 1) {
            header('HTTP/1.0 400 Bad Request');
            echo new ErrorMessage(400, 'Bad Request', 'No accepted attributes were passed');
        }

        // update attributes
        $sql = "UPDATE files SET ".implode(", ", $accepted_updates)." WHERE id = $id";
        if($mysqli->query($sql)) {
            echo new SuccessMessage('Attributes changed');
        } else {
            header('HTTP/1.0 400 Internal Server Error');
            echo new ErrorMessage(400, 'Internal Server Error', 'Attributes could not be set because of a SQL-Error');
        }

    }

    /**
     * Get the access of the current user for the passed element
     * @param int $id
     *          The ID of the element
     * @param string $type
     *          'file' or 'folder'
     * @echo string
     *          A JSON String with the own access
     */
    static function getAccess($id, $type = 'file') {
        echo json_encode(array("access" => Util::get_access($id, $type)));
    }

    /**
     * Get all access rights for the requested element
     * @param int $id
     *          The ID of the element
     * @param string $type
     *          'file' or 'folder'
     * @echo string
     *          A JSON String with all access rights
     */
    static function getRights($id, $type = 'file') {
        $mysqli = System::connect('cloud');

        // Escape values
        $id = $mysqli->real_escape_string($id);
        $type = $mysqli->real_escape_string($type);

        if(!Util::has_admin_access($id, $type)) {
            header('HTTP/1.0 403 Forbidden');
            echo (new ErrorMessage(403, 'Forbidden', 'You do not have admin access, so you can not see the rights'))->getJSON();
            die;
        }

        // get rights
        $sql = "SELECT rights.user_id, rights.access
                FROM rights
                WHERE
                rights.data_type LIKE '$type' AND
                rights.data_id = ".$id;
        $data = array();
        if($result = $mysqli->query($sql)) {
            while($r = $result->fetch_assoc()) {
                $data[] = $r;
            }
        }

        //Print result
        echo json_encode($data);
    }

    /**
     * Updates the rights of the requested element
     * @param int $id
     *          The ID of the element to be updated
     * @param string[] $user
     *          All users who's rights have to be set
     * @param string[] $access
     *          The new access rights of the users
     * @param string $type
     *          'file' or 'folder'
     * @echo string
     *          A JSON String whether the update was successful
     */
    static function setRights($id, $user, $access, $type = 'file') {
        $mysqli = System::connect('cloud');
        $current_user = System::getCurrentUser();

        $id = $mysqli->real_escape_string($id);
        $user = $mysqli->real_escape_string($user);
        $access = $mysqli->real_escape_string($access);
        $type = $mysqli->real_escape_string($type);

        // check admin access
        if(!Util::has_admin_access($id, $type)) {
            header('HTTP/1.0 403 Forbidden');
            echo "Forbidden";
            exit;
        }

        // remove all current access rights
        $sql = "DELETE FROM rights WHERE data_type='$type' AND data_id=$id AND user_id != ".$current_user->id;
        $mysqli->query($sql);

        // loop all set access rights
        for($i = 0; $i < count($user); $i++) {
            $loop_user = $mysqli->real_escape_string($user[$i]);
            $loop_access = $mysqli->real_escape_string($access[$i]);

            // allow hot-link for public files
            if($type == 'file' && $loop_user == 1) {
                Util::set_chmod($id, ( $loop_access == "read" || $loop_access == "write" || $loop_access == "admin" ));
            }

            // Only set access rights for other user NEVER for the requester
            if($loop_user != '' && $loop_access != '' && $loop_user != $current_user->id) { // only use data with a user id
                $sql = "INSERT INTO rights
                    (access, user_id, data_type, data_id)
                    VALUES
                    ('$loop_access', '$loop_user', '$type', '$id')";
                $mysqli->query($sql);
            }

        } // end loop all rights

        header('HTTP/1.0 200 OK');
        echo new SuccessMessage('Rights have been updated');
    }

    /**
     * List the content of a folder
     * @param int $id
     *          The ID of the folder
     * @echo string
     *          A JSON String with all elements in the passed folder
     */
    static function getFolder($id) {
        $mysqli = System::connect('cloud');
        $current_user = System::getCurrentUser();

        $data = array();
        $id = $mysqli->real_escape_string($id);

        if(Util::has_read_access($id, 'folder')) { // allowed to see this folder
            // fetch folders
            if($current_user->admin) { // admin does not need rights
                $sql = "SELECT distinct folders.id, folders.name
                FROM folders
                WHERE folders.parent = $id
                ORDER BY folders.name";
            } else { // no-admin needs a access right for this folder
                $sql = "SELECT distinct folders.id, folders.name
                FROM folders, rights
                WHERE folders.parent = $id
                AND folders.id = rights.data_id
                AND rights.data_type LIKE 'folder'
                AND (rights.user_id = ".$current_user->id." OR rights.user_id = 1)
                ORDER BY folders.name";
            }
            if($result = $mysqli->query($sql)) {
                while($r = $result->fetch_assoc()) {
                    $r['data_type'] = 'folder';
                    $data[] = $r;
                }
            }

            // fetch files
            if($current_user->admin) { // admin does not need rights
                $sql = "SELECT files.id, files.title, files.type, files.filename, files.folder
                FROM files
                WHERE files.folder = $id
                ORDER BY files.title";
            } else { // no-admin needs a access right for this file
                $sql = "SELECT files.id, files.title, files.type, files.filename, files.folder
                FROM files, rights
                WHERE files.folder = $id
                AND files.id = rights.data_id
                AND rights.data_type LIKE 'file'
                AND (rights.user_id = ".$current_user->id." OR rights.user_id = 1)
                ORDER BY files.title";
            }
            if($result = $mysqli->query($sql)) {
                while($r = $result->fetch_assoc()) {
                    $r['data_type'] = 'file';
                    $r['hotlink'] = 'http://cloud.salzhimmel.de/uploads/'.$r['id'].'/'.$r['filename'];
                    $data[] = $r;
                }
            }
        } else {
            header('HTTP/1.0 403 Forbidden');
            $data = (new ErrorMessage(403, 'Forbidden', 'You do not have access to see this folder'))->getArray();
        }

        //Print result
        echo json_encode($data);
    }

    static function setFolder($name, $parent) {
        $mysqli = System::connect('cloud');
        $current_user = System::getCurrentUser();

        $name = $mysqli->real_escape_string($name);
        $parent = $mysqli->real_escape_string($parent);

        if(Util::has_write_access($parent, 'folder')) {
            // create folder
            $sql = "INSERT INTO folders (id, name, parent) VALUES (NULL, '$name', '$parent')";
            $mysqli->query($sql);
            $id = $mysqli->insert_id;

            // set admin access for current user
            $sql = "INSERT INTO rights (id, access, user_id, data_type, data_id) VALUES (NULL, 'admin', '".$current_user->id."', 'folder', '$id')";
            $mysqli->query($sql);

            $result['id'] = $id;
            echo json_encode($result);
        } else {
            header('HTTP/1.0 403 Forbidden');
            echo new ErrorMessage(403, 'Forbidden', 'You do not have access to create a folder here');
        }
    }

    /**
     * Get the ID of the parent folder from the folder with the passed ID
     * @param int $id
     *          The ID of the current folder
     * @echo string
     *          A JSON String with the ID of the parent folder
     */
    static function getParentFolder($id) {
        $mysqli = System::connect('cloud');

        $id = $mysqli->real_escape_string($id);

        if(Util::has_read_access($id, 'folder')) { // allowed to see this folder
            // fetch parent
            $sql = "SELECT folders.parent
                FROM folders
                WHERE folders.id = $id";
            $data = $mysqli->query($sql)->fetch_assoc();

            //Print result
            echo json_encode($data);
        } else {
            header('HTTP/1.0 403 Forbidden');
            echo new ErrorMessage(403, 'Forbidden', 'You do not have read access to this folder. So you can no see its parent');
        }

    }
}

class Util {

    static $uploadDir = './uploads';

    /**
     * creates a database entry for the file
     * creates admin rights for the owner
     * creates a directory for the file
     *
     * @param $title
     *      The title of the file ("My great file")
     * @param $type
     *      The type of the file ("image/jpeg")
     * @param $filename
     *      The filename of the original file ("image.jpg")
     * @param $folder
     *      The folder of where the file will be stored
     * @param $ownerID
     *      The ID of the owner with admin rights
     * @return int The generated ID for the file
     */
    static function prepareForNewFile($title, $type, $filename, $folder, $ownerID) {
        $mysqli = System::connect('cloud');

        // Escape values
        $title = $mysqli->real_escape_string($title);
        $type = $mysqli->real_escape_string($type);
        $filename = $mysqli->real_escape_string($filename);
        $folder = $mysqli->real_escape_string($folder);
        $ownerID = $mysqli->real_escape_string($ownerID);

        //Register File in DB
        $sql = "INSERT INTO files
            (id, title, type, filename, folder)
            VALUES
            (NULL, '$title', '$type', '$filename', $folder)";
        $mysqli->query($sql);
        $id = $mysqli->insert_id;
        mkdir(Util::$uploadDir."/$id", 0700);

        // add access right for uploader
        $sql = "INSERT INTO rights
                    (access, user_id, data_type, data_id)
                    VALUES
                    ('admin', '".$ownerID."', 'file', '$id')";
        $mysqli->query($sql);

        return $id;
    }

    /**
     * get a filename for the passed file which can be used to store it.
     * @param $file : The original filename
     * @param string $prefix: A possible prefix of the file
     * @return string: The new filename to be used
     */
    static function get_filename_for($file, $prefix = '') {
        $first = array(" ", "&", "ä", "ö", "ü", "Ä", "Ö", "Ü", "ß", "<", ">", "€", "¹", "²", "³");
        $replaced = array("_", "_", "ae", "oe", "ue", "Ae;", "Oe", "Ue", "ss", "_", "_", "_Euro", "1", "2", "3");
        return strtolower(preg_replace('/[^A-Za-z0-9\-\._]/', '', str_replace($first, $replaced, $prefix.$file)));
    }
    static function has_admin_access($elem, $type = 'file', $current_user = null) {
        return Util::has_access($elem, 'admin', $type, $current_user);
    }
    static function has_write_access($elem, $type = 'file', $current_user = null) {
        return Util::has_access($elem, 'write', $type, $current_user);
    }
    static function has_read_access($elem, $type = 'file', $current_user = null) {
        return Util::has_access($elem, 'read', $type, $current_user);
    }
    private static function has_access($elem, $access, $type = 'file', $current_user = null) {
        $found_access = Util::get_access($elem, $type, $current_user);
        //echo 'Found access: '.$found_access.' requested access: '.$access;
        if($access == 'admin' && $found_access == 'admin') return true;
        if($access == 'write' && ($found_access == 'admin' || $found_access == 'write')) return true;
        if($access == 'read' && ($found_access == 'admin' || $found_access == 'write' || $found_access == 'read')) return true;

        return false;
    }
    public static function get_access($elem, $type = 'file', $current_user = null) {
        $mysqli = System::connect('cloud');

        // Escape values
        $elem = $mysqli->real_escape_string($elem);
        $type = $mysqli->real_escape_string($type);

        if($current_user == null) $current_user = System::getCurrentUser();

        // check admin
        if($current_user->admin) return 'admin';

        $type = $mysqli->real_escape_string($type);
        $elem = $mysqli->real_escape_string($elem);

        $sql = "SELECT rights.access
            FROM rights
            WHERE
                (rights.user_id = ".$current_user->id." OR rights.user_id = 1) AND
                rights.data_type LIKE '$type' AND
                rights.data_id = $elem";

        // check results
        $highest_access_right = 'none';
        if($result = $mysqli->query($sql)) {
            while($found_access = $result->fetch_assoc()['access']) { // loop because maybe a 'public' and a 'personal' access
                if($found_access == 'admin' ||
                    ($found_access == 'write' && ($highest_access_right == 'none' || $highest_access_right == 'read')) ||
                    ($found_access == 'read' && $highest_access_right == 'none')) {
                    $highest_access_right = $found_access;
                }
            }
        }
        return $highest_access_right;
    }

    /**
     * Allows or disables hot link access for public folders
     * @param int $id
     * @param boolean $public
     */
    public static function set_chmod($id, $public) {
        $pathname = Util::$uploadDir."/$id";
        if($public) {
            $file_mode = 0644;
            $folder_mode = 0755;
        } else {
            $file_mode = 0640;
            $folder_mode = 0700;
        }
        chmod($pathname, $folder_mode);
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pathname));
        foreach($iterator as $item) {
            if($item != "." && $item != ".." && !is_dir($item)) chmod($item, $file_mode);
        }
    }
}

class ErrorMessage {
    private $code;
    private $status;
    private $message;

    function __construct($code, $status, $message) {
        $this->code = $code;
        $this->status = $status;
        $this->message = $message;
    }

    function getArray() {
        return array('error' => array('code' => $this->code, 'status' => $this->status, 'message' => $this->message));
    }

    function getJSON() {
        return json_encode($this->getArray());
    }

    function __toString() {
        return $this->getJSON();
    }
}

class SuccessMessage {
    private $message;

    function __construct($message) {
        $this->message = $message;
    }

    function getArray() {
        return array('success' => array('code' => 200, 'status' => 'OK', 'message' => $this->message));
    }

    function getJSON() {
        return json_encode($this->getArray());
    }

    function __toString() {
        return $this->getJSON();
    }
}



if(false){;}
else if($_GET['q'] == 'get_current_user' || $_GET['q'] == 'whoami') { // return information of the current user
    CloudAPI::getCurrentUser();

} else if($_GET['q'] == 'get_file') { // gets the uploaded file with the passed ID and the wished width
    CloudAPI::getFile($_GET['id'], (isset($_GET['w']) ? $_GET['w'] : array()));

} else if($_GET['q'] == 'set_file') { // sets the uploaded file for the passed ID
    $file = (isset($_FILES['file']) ? $_FILES['file'] : $_FILES[0]);
    CloudAPI::setFile($file, (isset($_POST['id']) ? $_POST['id'] : null), (isset($_POST['title']) ? $_POST['title'] : null), (isset($_POST['folder']) ? $_POST['folder'] : null));

} else if($_GET['q'] == 'copy_file') { // sets the uploaded file for the passed ID
    CloudAPI::setFile($_GET['url'], null, (isset($_GET['title']) ? $_GET['title'] : null), (isset($_GET['folder']) ? $_GET['folder'] : null));

} else if($_GET['q'] == 'delete_file') { // deletes the file with the passed id
    CloudAPI::deleteFile($_GET['id']);

} else if($_GET['q'] == 'get_file_attributes') { // get the attributes of a file
    CloudAPI::getFileAttributes($_GET['v']);

} else if($_GET['q'] == 'set_file_attributes') { // set the attributes of a file
    CloudAPI::setFileAttributes($_GET['v'], $_GET['w']);

} else if($_GET['q'] == 'get_access') { // get the access of the current user for the passed element
    CloudAPI::getAccess($_GET['v'], $_GET['w']);

} else if($_GET['q'] == 'get_rights') { // get the access rights of the file with the passed ID
    CloudAPI::getRights($_GET['v'], $_GET['w']);

} else if($_GET['q'] == 'set_rights') { // set the access rights of the file with the passed ID
    $type = (isset($_POST['type']) ? $_POST['type'] : 'file');
    if(! (isset($type) && isset($_POST['id']) && isset($_POST['user']) && isset($_POST['access'])) ) {
        header('HTTP/1.0 400 Bad Request');
        echo new ErrorMessage(400, 'Bad Request', 'All attributes have to be filled in');
        die;
    }
    CloudAPI::setRights($_POST['id'], $_POST['user'], $_POST['access'], $type);

} else if($_GET['q'] == 'set_folder') { // creates a new folder
    if(isset($_POST['name']) && isset($_POST['parent']))
        CloudAPI::setFolder($_POST['name'], $_POST['parent']);
    else
        CloudAPI::setFolder($_GET['name'], $_GET['parent']);

} else if($_GET['q'] == 'get_folder') { // list the content of the folder with the passed ID
    CloudAPI::getFolder($_GET['v']);

} else if($_GET['q'] == 'get_parent_folder') { // get the ID of the parent-folder from the folder with the passed ID
    CloudAPI::getParentFolder($_GET['id']);

} else {
    header('HTTP/1.0 418 I\'m a teapot');
    echo new ErrorMessage(418, 'I\'m a teapot', 'You called an undefined function, so I - the teapot of this site - answered your request. Nice to meet you!');

}