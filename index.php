<?php
include_once($_SERVER['DOCUMENT_ROOT']."/system/global.inc.php");

header('Content-Type: text/html; charset=UTF-8');
?>


<!DOCTYPE html>
<HTML lang="de">
<head>
    <meta name="robots" content="noindex">
    <meta name="description" content="Liste der verfügbaren Filme">
    <meta name="author" content="Hendrik Würz">
    <meta charset="utf-8">

    <title>
        SalzCloud
    </title>

    <script src="jquery.js"></script>
    <script>
        var current_page = "<?php echo System::getCurrentUrl(); ?>";
        var current_user = '<?php echo System::getCurrentUser()->get_json(); ?>';
    </script>

    <LINK type="text/css" href="salz_cloud.css" rel="stylesheet">
    <script type="text/javascript" src="salz_cloud.js"></script>


    <Link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico">

</head>


<body>
<div id="overlay"></div>

<div id="login" class="block">
    <form action="http://system.salzhimmel.de/api.php?q=login&von=<?php echo System::getCurrentUrl(); ?>" method="post">
        <h1>Login</h1>
        <span class="close"></span>

        <p>Bitte melden Sie sich an.</p>

        <div>
            <input name="name" placeholder="Benutzername">
            <input name="pw" type="password" placeholder="Passwort">
            <button>Anmelden</button>
        </div>
    </form>
</div>

<div id="upload" class="block">
    <h1>Hochladen</h1>
    <span class="close" onclick="$('html').removeClass('upload-visible')"></span>

    <p>Wählen Sie eine Datei aus, um sie hoch zu laden.</p>
    <input type="text" name="title" placeholder="Titel">
    <input type="file" name="file">
    <button>Hochladen</button>
</div>

<div id="details" class="block">
    <h2></h2>
    <span class="close"></span>

    <form action="#" method="get" name="attributes">
        <input type="hidden" name="type">
        <input type="hidden" name="id">

        <div class="header">
            Attribute
            <input type="submit" value="" class="save">
        </div>
        <div class="content">
            <div class="row">
                <label for="title">Titel</label>
                <input type="text" name="title" placeholder="Titel">
            </div>
            <div class="row">
                <label for="file">Datei</label>
                <input type="file" name="file">
            </div>
            <div class="row hotlink">
                <span>Hotlink</span>
                <a href="#"></a>
            </div>
            <div class="row api-link">
                <span>API-Link</span>
                <a href="#"></a>
            </div>
        </div>
    </form>
    <form action="#" method="get" name="access-rights">
        <input type="hidden" name="type">
        <input type="hidden" name="id">

        <div class="header">
            Zugriffsrechte
            <input type="button" class="add">
            <input type="submit" value="" class="save">
        </div>
        <div class="content">
            <div class="row template">
                <input placeholder="User-ID" name="user[]">
                <input placeholder="Access" name="access[]">
                <span class="remove"></span>
            </div>
        </div>
    </form>
    <form action="#" method="get" name="operations">
        <div class="header">
            Operationen
        </div>
        <div class="content">
            <span class="delete">Datei endgültig löschen</span>
        </div>
    </form>
    <img src="#" alt="" title="">
</div>

<div id="wait" class="block"><img src="img/wait.gif" alt="Bitte warten"></div>

<div id="success" class="block"><h2></h2>

    <p></p></div>

<div id="error" class="block"><h2></h2>

    <p></p></div>

<div id="footer">
    <span>
        <?php
        if(System::getCurrentUser()->is_logged_in()) { //display logout
            ?>Angemeldet als <?php echo System::getCurrentUser()->name; ?>  <a
                href="http://system.salzhimmel.de/api.php?q=logout&von=<?php echo System::getCurrentUrl(); ?>">Abmelden</a> <?php
        } else { //display login
            ?>Nicht angemeldet <a href="javascript:cloud.show_login();">Anmelden</a> <?php
        }
        ?>
    </span>
</div>

<div id="site">
    <div id="header">
        <a class="topic" href="index.php" title="Zur&uuml;ck zur Startseite">SalzCloud</a>
    </div>

    <div id="content">

        <div id="menu">
            <img class="upload" src="img/upload.svg" alt="[Hochladen]">
        </div>
        <!-- Area where files are displayed by JavaScript -->
        <div id="files">

        </div>
    </div>
    <!-- end content -->
</div>
<!-- end site -->

</body>
</html>