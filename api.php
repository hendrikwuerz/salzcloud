<?php

require_once($_SERVER['DOCUMENT_ROOT']."/system/global.inc.php");


class CloudAPI {

    /**
     * Return the current user that is using the system
     * @echo A JSON String with the current user
     * TODO: Add example
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
     * @echo int
     *          The final ID of the file
     */
    static function setFile($file, $id = null) {
        $mysqli = System::connect('cloud');
        $current_user = System::getCurrentUser();

        $type = $mysqli->real_escape_string($file['type']);
        $filename = Util::get_filename_for($file['name']);

        if($id == null || $id == '') { // new file

            //Register File in DB
            $sql = "INSERT INTO files
            (id, title, type, filename, folder)
            VALUES
            (NULL, '', '$type', '$filename', 1)";
            $mysqli->query($sql);
            $id = $mysqli->insert_id;
            mkdir(Util::$uploadDir."/$id", 0700);

            // add access right for uploader
            $sql = "INSERT INTO rights
                    (access, user_id, data_type, data_id)
                    VALUES
                    ('admin', '".$current_user->id."', 'file', '$id')";
            $mysqli->query($sql);

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

        // save scaled versions if it is an image
        $image_type = exif_imagetype($file['tmp_name']);
        // $image_type is false if this is not an image
        if($image_type && ($image_type == IMAGETYPE_JPEG || $image_type == IMAGETYPE_GIF || $image_type == IMAGETYPE_PNG)) {
            include_once("SimpleImage.php");
            $image = new SimpleImage();
            $image->load($file['tmp_name']);
            $image->resizeToWidth(400);
            $image->save(Util::$uploadDir."/$id/400width_$filename");
            $image->resizeToWidth(100);
            $image->save(Util::$uploadDir."/$id/100width_$filename");
        }

        // Save original file
        if(move_uploaded_file($file['tmp_name'], Util::$uploadDir."/$id/$filename")) {
            header('HTTP/1.0 200 OK');
            echo json_encode(array('id' => $id));
        } else {
            header('HTTP/1.0 400 Internal Server Error');
            echo new ErrorMessage(400, 'Internal Server Error', 'Your uploaded file could not be saved.');
        }
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
     * @param string $title
     *          The title of the file
     * @echo string
     *          A JSON String with whether the update was accepted
     */
    static function setFileAttributes($id, $title) {
        $mysqli = System::connect('cloud');

        // check write access
        if(!Util::has_write_access($id, 'file')) {
            header('HTTP/1.0 403 Forbidden');
            echo new ErrorMessage(403, 'Forbidden', 'You have no write access to the requested file.');
            exit;
        }

        // update attributes
        $sql = "UPDATE files SET title = '$title' WHERE id = $id";
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

        // check var
        // TODO: Move to api call function (bottom)
        if(! (isset($type) && isset($id) && isset($user) && isset($access)) ) {
            header('HTTP/1.0 400 Bad Request');
            echo new ErrorMessage(400, 'Bad Request', 'All attributes have to be filled in');
        }

        $type = $mysqli->real_escape_string($type);
        $id = $mysqli->real_escape_string($id);

        // check admin access
        if(!Util::has_admin_access($id, $type)) {
            header('HTTP/1.0 403 Forbidden');
            echo "Forbidden";
            exit;
        }

        // loop all set access rights
        for($i = 0; $i < count($user); $i++) {
            $loop_user = $mysqli->real_escape_string($user[$i]);
            $loop_access = $mysqli->real_escape_string($access[$i]);

            // allow hot-link for public files
            if($type == 'file' && $loop_user == 1) {
                $pathname = "./uploads/$id";
                if( $loop_access == "read" || $loop_access == "write" || $loop_access == "admin" ) {
                    $file_mode = 0644;
                    $folder_mode = 0755;
                } else { // $access == '' -> delete access
                    $file_mode = 0640;
                    $folder_mode = 0700;
                }
                chmod($pathname, $folder_mode);
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pathname));
                foreach($iterator as $item) {
                    if($item != "." && $item != ".." && !is_dir($item)) chmod($item, $file_mode);
                }
            }

            // Only set access rights for other user NEVER for the requester
            if($loop_user != '' && $loop_user != $current_user->id) { // only use data with a user id
                // check for remove right
                if($loop_access == '') {
                    $sql = "DELETE FROM rights
                    WHERE
                      rights.user_id = $loop_user AND
                      rights.data_type LIKE '$type' AND
                      rights.data_id = $id";
                    $mysqli->query($sql);


                } else { // Give user a right
                    $insert_new_row = true;

                    // check for existing entry
                    $sql = "SELECT rights.id, rights.access, rights.user_id, rights.data_type, rights.data_id
                    FROM rights
                    WHERE
                      rights.user_id = $loop_user AND
                      rights.data_type LIKE '$type' AND
                      rights.data_id = $id";
                    if($result = $mysqli->query($sql)) {
                        if($result->num_rows != 0) { // update existing right
                            $insert_new_row = false;
                            $row = $result->fetch_assoc();
                            if($row['access'] != $loop_access) { // changed current access right
                                $access_id = $row['id'];
                                $sql = "UPDATE rights
                                    SET access = '$loop_access'
                                    WHERE id = $access_id";
                                $mysqli->query($sql);
                            }
                        }
                    }

                    // have to insert a new row
                    if($insert_new_row) {
                        $sql = "INSERT INTO rights
                    (access, user_id, data_type, data_id)
                    VALUES
                    ('$loop_access', '$loop_user', '$type', '$id')";
                        $mysqli->query($sql);
                    } // insert new row
                } // delete or set
            } // skip data with no user id

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

        $data = array();
        $id = $mysqli->real_escape_string($id);

        if(Util::has_read_access($id, 'folder')) { // allowed to see this folder
            // fetch folders
            $sql = "SELECT distinct folders.id, folders.name, folders.lft
                FROM folders, (SELECT folders.lft as lft, folders.rgt as rgt FROM folders WHERE folders.id = $id) bounds
                WHERE folders.lft between bounds.lft and bounds.rgt";
            if($result = $mysqli->query($sql)) {
                while($r = $result->fetch_assoc()) {
                    $r['data_type'] = 'folder';
                    $data[] = $r;
                }
            }

            // fetch files
            $sql = "SELECT files.id, files.title, files.type, files.filename
                FROM files
                WHERE files.folder = $id";
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
}

class Util {

    static $uploadDir = './uploads';

    /**
     * get a filename for the passed file which can be used to store it.
     * @param $file : The original filename
     * @param string $prefix: A possible prefix of the file
     * @return string: The new filename to be used
     */
    static function get_filename_for($file, $prefix = '') {
        $first = array(" ", "&", "ä", "ö", "ü", "Ä", "Ö", "Ü", "ß", "<", ">", "€", "¹", "²", "³");
        $replaced = array("_", "_", "ae", "oe", "ue", "Ae;", "Oe", "Ue", "ss", "_", "_", "_Euro", "1", "2", "3");
        return str_replace($first, $replaced, $prefix.$file);
    }
    static function has_admin_access($elem, $type = 'file') {
        return Util::has_access($elem, 'admin', $type);
    }
    static function has_write_access($elem, $type = 'file') {
        return Util::has_access($elem, 'write', $type);
    }
    static function has_read_access($elem, $type = 'file') {
        return Util::has_access($elem, 'read', $type);
    }
    private static function has_access($elem, $access, $type = 'file') {
        $found_access = Util::get_access($elem, $type);
        //echo 'Found access: '.$found_access.' requested access: '.$access;
        if($access == 'admin' && $found_access == 'admin') return true;
        if($access == 'write' && ($found_access == 'admin' || $found_access == 'write')) return true;
        if($access == 'read' && ($found_access == 'admin' || $found_access == 'write' || $found_access == 'read')) return true;

        return false;
    }
    public static function get_access($elem, $type = 'file') {
        $mysqli = System::connect('cloud');
        $current_user = System::getCurrentUser();

        // check admin
        if($current_user->admin) return 'admin';

        $type = $mysqli->real_escape_string($type);
        $elem = $mysqli->real_escape_string($elem);

        $sql = "SELECT rights.access
            FROM rights
            WHERE
                rights.user_id = ".$current_user->id." AND
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


/**
 * RETURN the file with the passed id
 * @param $file_id
 *          the id of the file to be returned
 * @param $width
 *          "" or "400" or "100" - scaled versions of images. When this scale is not found the original image is returned
 * @param $height
 *          ignored at the moment *
 * return
 *          the file if accessible.
 *          Header "File-Title" the set title of the file
 *          Header "File-ID" the used ID in database for this file
 *          Header "File-Folder" the folder of the file
 *          404 if not found in database or no right to view
 *          503 if found in database but not on file system
 */
function download($file_id, $width="", $height="") {
    global $mysqli, $current_user;

    $has_right = $current_user->admin;
    $file_id = $mysqli->real_escape_string($file_id);

    if(!$has_right) { // no admin --> check for set rights
        // get rights
        $sql = "SELECT
                rights.access
            FROM rights
            WHERE
                rights.user_id = ".$current_user->id." AND
                rights.data_type LIKE 'file' AND
                rights.data_id = ".$file_id;

        // check rights
        if($result = $mysqli->query($sql)) {
            while($right = $result->fetch_assoc()) { // loop because maybe a 'public' and a 'personal' access
                $has_right = $has_right || $right['access'] == "read" || $right['access'] == "write" || $right['access'] == "admin";
            }
        }
    }


    if($has_right) { // allowed

        // user has access -> get file data
        $sql = "SELECT files.id, files.title, files.type, files.filename, files.folder
                FROM files
                WHERE files.id = ".$file_id;
        $file = $mysqli->query($sql)->fetch_assoc();

        // return file
        $attachment_location = './uploads/'.$file['id'].'/'.$file['filename'];

        include_once("SimpleImage.php");
        if(exif_imagetype($attachment_location) != false) { // Images can be found in scaled versions
            if($width == "400") $attachment_location = './uploads/'.$file['id'].'/400width_'.$file['filename'];
            elseif($width == "100") $attachment_location = './uploads/'.$file['id'].'/100width_'.$file['filename'];
        }

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
            exit;
        }
    }
    // not found or no right set for user-file connection
    header('HTTP/1.0 404 Not found');
    echo "Not found";
    exit;


}

/**
 * PRINT all files of the passed folder as json
 *
 * @param $folder
 *          the id of the folder to display content
 */
function list_content($folder) {
    global $mysqli, $current_user;

    $data = array();
    $folder = $mysqli->real_escape_string($folder);

    // fetch folders
    if($current_user->admin) {
        $sql = "SELECT distinct folders.id, folders.name, folders.lft
                FROM folders, (SELECT folders.lft as lft, folders.rgt as rgt FROM folders WHERE folders.id = $folder) bounds
                WHERE folders.lft between bounds.lft and bounds.rgt";
    } else {
        $sql = "SELECT distinct folders.id, folders.name, folders.lft
                FROM folders, rights, (SELECT folders.lft as lft, folders.rgt as rgt FROM folders WHERE folders.id = $folder) bounds
                WHERE
                    folders.lft between bounds.lft and bounds.rgt AND
                    (rights.user_id = ".$current_user->id." OR rights.user_id = 1) AND
                    rights.data_type LIKE 'folder' AND
                    rights.data_id = folders.id";
    }

    if($result = $mysqli->query($sql)) {
        while($r = $result->fetch_assoc()) {
            $r['data_type'] = 'folder';
            $data[] = $r;
        }
    }

    // fetch files
    if($current_user->admin) {
        $sql = "SELECT files.id, files.title, files.type, files.filename
                FROM files
                WHERE files.folder = $folder";
    } else {
        $sql = "SELECT distinct files.id, files.title, files.type, files.filename
                FROM files, rights
                WHERE
                    files.folder = $folder AND
                    (rights.user_id = ".$current_user->id." OR rights.user_id = 1) AND
                    rights.data_type LIKE 'file' AND
                    rights.data_id = files.id";
    }

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
 * PRINT the rights of the current user for the type and element passed
 * @param $type: The type: 'file' or 'folder'
 * @param $elem: The ID of the element
 */
function get_rights($type, $elem) {
    global $mysqli, $current_user;

    $data = array();
    $type = $mysqli->real_escape_string($type);
    $elem = $mysqli->real_escape_string($elem);

    if(!has_admin_access($type, $elem)) {
        header('HTTP/1.0 403 Forbidden');
        echo "Forbidden";
        exit;
    }

    // get rights
    $sql = "SELECT
                rights.user_id, rights.access
            FROM rights
            WHERE
                rights.data_type LIKE '$type' AND
                rights.data_id = ".$elem;
    if($result = $mysqli->query($sql)) {
        while($r = $result->fetch_assoc()) {
            $data[] = $r;
        }
    }

    //Print result
    echo json_encode($data);
}

/**
 * sets the rights in $_POST
 * Needs:
 *      $_POST['type'] - the type of element to set the rights for i.e. 'file' or 'folder'
 *      $_POST['elem'] - the ID of the element to set the rights for i.e. 5
 *      $_POST['user'][] - the user id to get access right
 *      $_POST['access'][] - the new access for the user
 * if possible existing rights become updated.
 */
function set_rights() {
    global $mysqli, $current_user;

    // check var
    if(! (isset($_POST['type']) && isset($_POST['elem']) && isset($_POST['user']) && isset($_POST['access'])) ) {
        header('HTTP/1.0 400 Bad Request');
        echo "Bad Request";
    }

    $type = $mysqli->real_escape_string($_POST['type']);
    $elem = $mysqli->real_escape_string($_POST['elem']);

    // check admin access
    if(!has_admin_access($type, $elem)) {
        header('HTTP/1.0 403 Forbidden');
        echo "Forbidden";
        exit;
    }

    // loop all set access rights
    for($i = 0; $i < count($_POST['user']); $i++) {
        $user = $mysqli->real_escape_string($_POST['user'][$i]);
        $access = $mysqli->real_escape_string($_POST['access'][$i]);

        // allow hot-link for public files
        if($type == 'file' && $user == 1) {
            $sql = "SELECT id FROM files WHERE id = $elem";
            $result = $mysqli->query($sql)->fetch_assoc();
            $pathname = "./uploads/".$result['id'];
            if( $access == "read" || $access == "write" || $access == "admin" ) {
                $file_mode = 0644;
                $folder_mode = 0755;
            } else { // $access == '' -> delete access
                $file_mode = 0640;
                $folder_mode = 0700;
            }
            chmod($pathname, $folder_mode);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pathname));
            foreach($iterator as $item) {
                if($item != "." && $item != ".." && !is_dir($item)) chmod($item, $file_mode);
            }
        }

        // Only set access rights for other user NEVER for the requester
        if($user != '' && $user != $current_user->id) { // only use data with a user id
            // check for remove right
            if($access == '') {
                $sql = "DELETE FROM rights
                    WHERE
                      rights.user_id = $user AND
                      rights.data_type LIKE '$type' AND
                      rights.data_id = $elem";
                $mysqli->query($sql);


            } else {
                $insert_new_row = true;

                // check for existing entry
                $sql = "SELECT rights.id, rights.access, rights.user_id, rights.data_type, rights.data_id
                    FROM rights
                    WHERE
                      rights.user_id = $user AND
                      rights.data_type LIKE '$type' AND
                      rights.data_id = $elem";
                if($result = $mysqli->query($sql)) {
                    if($result->num_rows != 0) { // update existing right
                        $insert_new_row = false;
                        $row = $result->fetch_assoc();
                        if($row['access'] != $access) { // changed current access right
                            $id = $row['id'];
                            $sql = "UPDATE rights
                                    SET access = '$access'
                                    WHERE id = $id";
                            $mysqli->query($sql);
                        }
                    }
                }

                // have to insert a new row
                if($insert_new_row) {
                    $sql = "INSERT INTO rights
                    (access, user_id, data_type, data_id)
                    VALUES
                    ('$access', '$user', '$type', '$elem')";
                    $mysqli->query($sql);
                } // insert new row
            } // delete or set
        } // skip data with no user id

    } // end loop all rights

    header('HTTP/1.0 200 OK');
    echo "OK";
}

/**
 * sets the file in $_POST and the title of it.
 * If passed an ID in $_POST['elem'] this one will be updated. Else a new File is created
 * Uses:
 *      $_POST['elem'] - the ID of the element to be updated or '' / not set if creating a new one
 *      $_POST['title'] - the title of the file
 * if possible existing files become updated.
 */
function set_file() {
    global $mysqli, $current_user;

    // check var
    if( !isset($_POST['title']) ) {
        header('HTTP/1.0 400 Bad Request');
        echo "Bad Request";
        exit;
    }

    $title = $mysqli->real_escape_string($_POST['title']);

    if(isset($_POST['elem']) && $_POST['elem'] != '') { // update existing data

        $elem = $mysqli->real_escape_string($_POST['elem']);

        // check write access
        if(!has_write_access('file', $elem)) {
            header('HTTP/1.0 403 Forbidden');
            echo "Forbidden";
            exit;
        }

        // update attributes
        $sql = "UPDATE files SET title = '$title' WHERE id = $elem";
        if(count($_FILES) > 0) { // new filename
            $sql = "UPDATE files SET title = '$title', filename = '".get_filename_for($_FILES[0])."' WHERE id = $elem";
        }
        $mysqli->query($sql);

        // check if update the image is required
        if(count($_FILES) > 0) {

            // delete old file
            $existing_files = glob("./uploads/$elem/*"); // get all file names
            foreach($existing_files as $deletable_file){ // iterate files
                if(is_file($deletable_file)) unlink($deletable_file); // delete file
            }

            // create new file
            $file = $_FILES[0];

            // save scaled versions if it is an image
            $image_type = exif_imagetype($file['tmp_name']);
            // $image_type is false if this is not an image
            if($image_type && ($image_type == IMAGETYPE_JPEG || $image_type == IMAGETYPE_GIF || $image_type == IMAGETYPE_PNG)) {
                include_once("SimpleImage.php");
                $image = new SimpleImage();
                $image->load($file['tmp_name']);
                $image->resizeToWidth(100);
                $image->save(get_path_for($file, $elem, "100width_"));
                $image->resizeToWidth(400);
                $image->save(get_path_for($file, $elem, '400width_'));
            }

            // save original
            if(move_uploaded_file($file['tmp_name'], get_path_for($file, $elem))) {
                header('HTTP/1.0 200 OK');
                echo "OK";
            } else {
                header('HTTP/1.0 400 Internal Server Error');
                echo "Internal Server Error";
            }

            // set as private
            $file_mode = 0640;
            $folder_mode = 0700;
            $pathname = "./uploads/".$elem;
            chmod($pathname, $folder_mode);
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pathname));
            foreach($iterator as $item) {
                if($item != "." && $item != ".." && !is_dir($item)) chmod($item, $file_mode);
            }
        }

    } else { // create new file

        // check file to be passed
        if(count($_FILES) < 1) {
            header('HTTP/1.0 400 Bad Request');
            echo "Bad Request";
            exit;
        }

        //normally only one file is passed
        $file = $_FILES[0];
        $type = $mysqli->real_escape_string($file['type']);
        $filename = get_filename_for($file);

        //Register File in DB
        $sql = "INSERT INTO files
            (id, title, type, filename, folder)
            VALUES
            (NULL, '$title', '$type', '$filename', 1)";
        $mysqli->query($sql);
        $used_id = $mysqli->insert_id;

        $final_path = get_path_for($file, $used_id);

        mkdir("./uploads/".$used_id, 0700);

        if(move_uploaded_file($file['tmp_name'], $final_path)) {
            header('HTTP/1.0 200 OK');
            echo "OK";
        } else {
            header('HTTP/1.0 400 Internal Server Error');
            echo "Internal Server Error";
        }

        // add access right for uploader
        $sql = "INSERT INTO rights
                    (access, user_id, data_type, data_id)
                    VALUES
                    ('admin', '".$current_user->id."', 'file', '$used_id')";
        $mysqli->query($sql);
    }

}

/**
 * Get the final path for the passed file under the passed ID
 * @param $file : The file to be stored
 * @param $id : The Database ID of the file
 * @param string $prefix: A possible prefix of the file
 * @return string: The final path of the file
 */
function get_path_for($file, $id, $prefix = '') {
    return "uploads/$id/".get_filename_for($file, $prefix);
}

/**
 * get a filename for the passed file which can be used to store it.
 * @param $file : The file to e stored
 * @param string $prefix: A possible prefix of the file
 * @return string: The filename to be used
 */
function get_filename_for($file, $prefix = '') {
    global $mysqli, $current_user;
    return str_replace(" ", "_", $mysqli->real_escape_string($prefix.$file['name']));
}

/**
 * PRINT a JSON String with the current user data
 */
function current_user() {
    global $mysqli, $current_user;
    echo $current_user->get_json();
}

function has_admin_access($type, $elem) { return has_access('admin', $type, $elem);}
function has_write_access($type, $elem) { return has_access('write', $type, $elem);}
function has_read_access($type, $elem) { return has_access('read', $type, $elem);}
function has_access($access, $type, $elem) {
    global $mysqli, $current_user;

    // check admin
    if($current_user->admin) return true;

    $access = $mysqli->real_escape_string($access);
    $type = $mysqli->real_escape_string($type);
    $elem = $mysqli->real_escape_string($elem);

    $found_access = get_access($type, $elem);
    if($access == 'admin' && $found_access == 'admin') return true;
    if($access == 'write' && ($found_access == 'admin' || $found_access == 'write')) return true;
    if($access == 'read' && ($found_access == 'admin' || $found_access == 'write' || $found_access == 'read')) return true;

    return false;
}

function get_access($type, $elem) {
    global $mysqli, $current_user;

    // check admin
    if($current_user->admin) return 'admin';

    $type = $mysqli->real_escape_string($type);
    $elem = $mysqli->real_escape_string($elem);

    $sql = "SELECT rights.access
            FROM rights
            WHERE
                rights.user_id = ".$current_user->id." AND
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

if(false){;}
/*
else if($_GET['q'] == 'download') CloudAPI::getFile($_GET['v'], (isset($_GET['w']) ? array('width'=>$_GET['w']) : array())); // gets the uploaded file with the passed ID and the wished width
else if($_GET['q'] == 'list_content') list_content($_GET['v']); // lists the content of the folder with the passed ID
else if($_GET['q'] == 'get_rights') get_rights($_GET['v'], $_GET['w']);  // get the set rights for the passed type and element
else if($_GET['q'] == 'set_rights') set_rights(); // sets the rights for a file / folder
else if($_GET['q'] == 'set_file') set_file(); // updates the image and saves attributes
else if($_GET['q'] == 'current_user') current_user(); // gets the current user
else if($_GET['q'] == 'get_access') echo get_access($_GET['v'], $_GET['w']); // gets the access of the requester for the passed type and element
*/
else if($_GET['q'] == 'get_current_user') { // list the content of the folder with the passed ID
    CloudAPI::getCurrentUser();

} else if($_GET['q'] == 'get_file') { // gets the uploaded file with the passed ID and the wished width
    CloudAPI::getFile($_GET['id'], (isset($_GET['w']) ? array('width'=>$_GET['w']) : array()));

} else if($_GET['q'] == 'set_file') { // sets the uploaded file for the passed ID
    CloudAPI::setFile($_FILES[0], $_POST['id']);

} else if($_GET['q'] == 'get_file_attributes') { // get the attributes of a file
    CloudAPI::getFileAttributes($_GET['v']);

} else if($_GET['q'] == 'set_file_attributes') { // set the attributes of a file
    CloudAPI::setFileAttributes($_GET['v'], $_GET['w']);

} else if($_GET['q'] == 'get_access') { // get the access of the current user for the passed element
    CloudAPI::getAccess($_GET['v'], $_GET['w']);

} else if($_GET['q'] == 'get_rights') { // get the access rights of the file with the passed ID
    CloudAPI::getRights($_GET['v'], $_GET['w']);

} else if($_GET['q'] == 'set_rights') { // set the access rights of the file with the passed ID
    CloudAPI::setRights($_POST['id'], $_POST['user'], $_POST['access']);

} else if($_GET['q'] == 'get_folder') { // list the content of the folder with the passed ID
    CloudAPI::getFolder($_GET['v']);
}