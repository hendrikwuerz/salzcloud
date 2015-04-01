
$(function() {

    cloud = this;

    var storage = {};

    var html = $('html');
    var overlay = $('#overlay');
    var success = $('#success');
    var error = $('#error');
    var login = $('#login');
    var menu = $('#menu');
    var details = $('#details');

    function init() {

        // for ajax request
        document.domain = "salzhimmel.de";

        // init storage
        storage.current_user = null; // the current user -> set later by ajax request
        storage.current_folder = 1; // the current visible folder
        storage.selectedFiles = []; // files selected for upload
        storage.jq_files = $('#files'); // site area to display files (and folders)
        storage.popups = []; // visible popups Stack - FIFO
        storage.waitStack = 0; // how many wait requests are active

        // get current user
        $.ajax({
            url: 'api.php?q=current_user',
            type: 'GET',
            dataType: 'json'
        }).done(function(data) {
            storage.current_user = data;
        }).fail(function(jqXHR, textStatus, errorThrown) {
            storage.current_user = {id:1, name:"anonymous", admin:"false"};
        });

        // register listener
        overlay.on('click', function(){closePopup()});
        success.on('click', function(){closePopup('success-visible')});
        error.on('click', function(){closePopup('error-visible')});
        login.find('.close').on('click', function(){closePopup("login-visible")});
        menu.find('.upload').on('click', newFile);
        details.find('input[type=file]').on('change', function(event) {storage.selectedFiles = event.target.files;});
        details.find('.close').on('click', function(){closePopup("details-visible")});
        details.find('form[name=attributes]').submit('click', function(event) {uploadFileData(); return false;});
        details.find('form[name=attributes] .save').on('click', function(event) {$(event.target).submit();});
        details.find('form[name=access-rights] .save').on('click', function(event) {console.log('UPDATE RIGHTS'); updateRights(); return false;});
        details.find('form[name=access-rights] .add').on('click', addRightsField);

        // load root folder
        get_folder();
    }

    /*
    * PUBLIC
     */
    this.show_login = show_login;
    this.show_folder = get_folder;
    this.listPopups = listPopups;

    //show login_screen
    function show_login() {
        addPopup('login-visible');
        $('#login').find('input[name=name]').focus();
    }

    // display the content of the folder with the passed id
    // or reloads current folder if no value is passed
    function get_folder(id) {
        if(id == undefined) id = storage.current_folder;
        $.get( "api.php?q=get_folder&v=" + id, function( data ) {
            //console.log( data );
            storage.jq_files.html(""); // remove old elements
            var obj = jQuery.parseJSON( data );
            $.each(obj, function(index, elem) {
                var keys = Object.keys(elem);
                var data = "";
                $.each(keys, function(index, value) {
                    data += " data-" + value + "='" + elem[value] + "'";
                });
                var html = "<div class='" + elem.data_type + "'" + data + "><span>" + (elem.data_type == 'folder' ? elem.name : elem.title) + "</span></div>";

                storage.jq_files.append(html);
            });
            console.log('finished loading folder ' + id);
            storage.jq_files.find('> div').on('click', showFile);
        });
    }

    function listPopups() {
        console.log(storage.popups);
    }




    /*
     * PRIVATE
     */

    function newFile() {
        details.addClass('new-file');

        // clear old values
        details.find('h2').html('Neue Datei');
        details.removeClass('image');

        var attribute_form = details.find('form[name=attributes]');
        attribute_form.find('input[name=type]').val('file');
        attribute_form.find('input[name=elem]').val('');
        attribute_form.find('input[name=title]').val('');
        attribute_form.css('display', 'block');

        var access_form = details.find("form[name='access-rights']");
        access_form.css('display', 'none');

        addPopup('details-visible');
    }

    // shows details to the passed file
    function showFile(event) {
        addPopup('details-visible');

        loading(true);

        var elem = $(event.currentTarget);

        // DISPLAY DATA (READ)
        details.find('h2').html( elem.attr('data-type') == 'folder' ? elem.attr('data-name') : elem.attr('data-title') );
        details.find('.hotlink a').attr('href', elem.attr('data-hotlink') );
        details.find('.hotlink a').html(elem.attr('data-hotlink') );
        details.find('.api-link a').attr('href', "http://cloud.salzhimmel.de/api.php?q=get_file&v=" + elem.attr('data-id') );
        details.find('.api-link a').html("http://cloud.salzhimmel.de/api.php?q=get_file&v=" + elem.attr('data-id') );
        var img = details.find('img');
        var type = elem.attr('data-type');
        if(type.length > 'image'.length && type.slice(0, 'image'.length) == 'image') { // file is an image -> display
            details.addClass('image');
            // First show loading image
            img.attr('title', 'Wird geladen...');
            img.attr('alt', 'loading');
            img.attr('src', 'img/wait.gif');
            // than display real image
            img.attr('title', elem.attr('data-title'));
            img.attr('alt', elem.attr('data-title'));
            img.attr('src', 'api.php?q=get_file&v=' + elem.attr('data-id') + '&w=400&t=' + new Date().getTime());
        } else {
            details.removeClass('image');
        }

        // GET ACCESS RIGHTS
        var admin = false;
        var write = false;
        var read = false;
        $.get( "api.php?q=get_access&v=" + elem.attr('data-id') + "&w=" + elem.attr('data-data_type') )
            .done(function(data) {
                var access = jQuery.parseJSON( data )['access'];
                // set vars for access rights
                if(access == 'admin') {
                    admin = write = read = true;
                } else if(access == 'write') {
                    write = read = true;
                } else if(access == 'read') {
                    read = true;
                }

            }).always(function() {

                // ATTRIBUTE FORM (WRITE)
                var attribute_form = details.find('form[name=attributes]');
                if(write) {
                    attribute_form.find('input[name=type]').val(elem.attr('data-data_type'));
                    attribute_form.find('input[name=elem]').val(elem.attr('data-id'));
                    attribute_form.find('input[name=title]').val( elem.attr('data-title') );
                    attribute_form.css('display', 'block');
                } else {
                    attribute_form.css('display', 'none');
                }

                // ACCESS RIGHTS (ADMIN)
                var access_form = details.find("form[name='access-rights']");
                if(admin) {
                    access_form.addClass('loading');

                    // remove "old" rights
                    var row_template = access_form.find('div.row.template');
                    access_form.find('div.row:not(.template)').remove();

                    // set current type ('file') and element ID
                    access_form.find('input[name=type]').val(elem.attr('data-data_type'));
                    access_form.find('input[name=elem]').val(elem.attr('data-id'));

                    // display new access rights
                    $.get( "api.php?q=get_rights&v=" + elem.attr('data-id') + "&w=" + elem.attr('data-data_type'), function( data ) {
                        var obj = jQuery.parseJSON( data );
                        $.each(obj, function(index, elem) {
                            if(elem['user_id'] != storage.current_user.id) {
                                var row = row_template.clone();
                                row.removeClass('template');
                                row.find("input[name='user[]']").val(elem['user_id']);
                                row.find("input[name='access[]']").val(elem['access']);
                                access_form.find('div.content').append(row);
                            }
                        });

                        // add remove row callback
                        access_form.find('.row .remove').on('click', removeRightsField);
                    }).always(function() {
                        access_form.removeClass('loading');
                    });

                    access_form.css('display', 'block');
                } else {
                    access_form.css('display', 'none');
                }
                loading(false);
            });
    }

    /**
     * if file details are shown this function will add another row to enter access rights
     */
    function addRightsField() {
        var row = details.find("form[name='access-rights'] .row.template").clone();
        row.removeClass('template');
        row.find('.remove').on('click', removeRightsField);
        details.find('form[name=access-rights] .content').append(row);
    }

    /**
     * if file details are shown this function will remove the row of access rights with the clicked icon
     */
    function removeRightsField(event) {
        var row = $(event.target).parent();
        var user = row.find("input[name='user[]']").val();
        if(user == '' || user == storage.current_user.id) { // no need to update anything
            row.remove();
        } else { // delete rights of the user
            row.find("input[name='access[]']").val('');
            row.css('display', 'none');
        }
    }


    // uploads the files filled in in the upload form
    // and the set title for it
    function uploadFileData() {

        loading(true);

        // remove focus from from so ENTER will not submit it again
        $(window).focus();

        // Create a formdata object and add the files
        var data = new FormData();
        data.append('type', details.find('input[name=type]').val()); // type -> 'file'
        data.append('elem', details.find('input[name=elem]').val()); // ID if updating else ''
        data.append('title', details.find('input[name=title]').val()); // file title
        $.each(storage.selectedFiles, function(key, value) {
            data.append(key, value);
        });

        console.log('data: ' + data);

        $.ajax({
            url: 'api.php?q=set_file',
            type: 'POST',
            data: data,
            cache: false,
            dataType: 'html',
            processData: false, // Don't process the files
            contentType: false // Set content type to false as jQuery will tell the server its a query string request
        }).done(function(data, textStatus, jqXHR) {
            if(data.error === undefined) { // Success
                showSuccess('Gespeichert', 'Die Daten wurden erfolgreich gespeichert.');
                if(storage.selectedFiles != [] && details.find('input[name=elem]').val() != '') { // uploaded a new image -> refresh
                    details.find('img').attr('src', 'api.php?q=download&v=' + details.find('input[name=elem]').val() + '&w=400&t=' + new Date().getTime());
                }
                closePopup("details-visible");
            } else { // Server-side error
                console.log('Server-Side UPLOAD ERROR: ' + data.error);
                showError('Fehler', 'Die Daten konnten wegen einem Server-Fehler nicht gespeichert werden');
            }
            closePopup('upload-visible');
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.log('JQuery UPLOAD ERROR: ' + textStatus);
            showError('Fehler', 'JQuery konnte den Upload-Vorgang nicht ausführen<br>' + textStatus);
        }).always(function() {
            storage.selectedFiles = [];
            loading(false);
            get_folder();
        });
    }

    function updateRights() {
        loading(true);
        $.ajax({
            url: 'api.php?q=set_rights',
            type: 'POST',
            data: details.find('form[name=access-rights]').serialize(),
            dataType: 'html'
        }).done(function(data, textStatus, jqXHR) {
            console.log('Rights were set correctly');
            showSuccess('Gespeichert', 'Die Rechte wurden erfolgreich gesetzt');
        }).fail(function(jqXHR, textStatus, errorThrown) {
            console.log('Rights could not be set correctly ' + textStatus);
            console.log(errorThrown);
            showError('Fehler', 'Die Rechte konnten nicht gesetzt werden. Der Server hat die Anfrage nicht ausgeführt.');
        }).always(function() {
            loading(false);
        });
    }

    function showSuccess(title, content, time) {
        if(storage.noMessages) return;
        if(time === undefined) time = 5000;
        success.find('h2').html(title);
        success.find('p').html(content);
        addPopup('success-visible');
        setTimeout(function() {
            closePopup('success-visible');
        }, time);
    }

    function showError(title, content, time) {
        if(storage.noMessages) return;
        if(time === undefined) time = 5000;
        error.find('h2').html(title);
        error.find('p').html(content);
        addPopup('error-visible');
        setTimeout(function() {
            closePopup('error-visible');
        }, time);
    }

    function addPopup(css_class) {
        html.addClass(css_class);
        storage.popups.push(css_class);
        layoutPopups();
    }

    function closePopup(css_class) {
        if(storage.freezePopups) return;
        if(css_class === undefined) {
            html.removeClass(storage.popups.pop());
        } else {
            var index = storage.popups.indexOf(css_class);
            if(index >= 0) {
                storage.popups.splice(index, 1);
                html.removeClass(css_class);
            }
        }
        layoutPopups();
    }

    function layoutPopups() {
        for(var i = 0; i < storage.popups.length; i++) {
            var elem = storage.popups[i].split("-visible")[0];
            $('#' + elem).css('z-index', 200 + i*2);
            if(i == storage.popups.length - 1) { // last popup
                overlay.css('z-index', 200 + i*2 - 1);
            }
        }
    }

    function loading(enabled) {

        if(enabled) {
            storage.waitStack++;
        } else {
            storage.waitStack--;
        }

        if(storage.waitStack == 0) {
            html.removeClass('wait');
            storage.freezePopups = false;
        } else {
            html.addClass('wait');
            storage.freezePopups = true;
        }
    }

    init();

});