var cloud;

$(function() {

    cloud = this;

    var config = {};
    config.jq_files_id = '#files';
    config.domain = 'salzhimmel.de';
    config.default_layout = 'grid';

    var storage = {};

    var html = $('html');
    var overlay = $('#overlay');
    var success = $('#success');
    var error = $('#error');
    var login = $('#login');
    var menu = $('#menu');
    var details = $('#details');
    var newFolder = $('#new-folder');

    function init() {

        // for ajax request
        document.domain = config.domain;

        // init storage
        storage.current_user = null; // the current user -> set later by ajax request
        storage.current_folder = (window.location.hash == '' ? 1 : window.location.hash.substring(1)); // the current visible folder
        storage.selectedFiles = []; // files selected for upload
        storage.jq_files = $(config.jq_files_id); // site area to display files (and folders)
        storage.popups = []; // visible popups Stack - FIFO
        storage.waitStack = 0; // how many wait requests are active
        storage.noMessages = false; // disable the display of any message pop-ups
        storage.layout = config.default_layout; // disable the display of any message pop-ups

        // get current user
        api.get_current_user()
            .done(function(data) {
                storage.current_user = data;
            }).fail(function(jqXHR, textStatus, errorThrown) {
                storage.current_user = {id:1, name:"anonymous", admin:"false"};
                console.log(jqXHR);
                console.log(textStatus);
                console.log(errorThrown);
            });

        // register listener
        menu.find('.folder-up').on('click', function(){folderUp()});
        menu.find('.folder-new').on('click', function(){showNewFolder()});
        menu.find('.file-new').on('click', function(){newFile()});
        menu.find('.reload').on('click', function(){get_folder()});
        menu.find('.layout-list').on('click', function(){setLayout('list')});
        menu.find('.layout-grid').on('click', function(){setLayout('grid')});

        overlay.on('click', function(){closePopup()});
        success.on('click', function(){closePopup('success-visible')});
        success.on('keydown', function(e){if(e.keyCode == 13)closePopup('success-visible')});
        error.on('click', function(){closePopup('error-visible')});
        login.find('.close').on('click', function(){closePopup("login-visible")});
        newFolder.find('form').submit('click', function() {createNewFolder(); return false;});
        details.find('input[type=file]').on('change', function(event) {storage.selectedFiles = event.target.files; var title_input = details.find("form[name=attributes] input[name=title]"); if(title_input.val() == '') title_input.val(event.target.files[0].name); console.log(event.target.files[0].name);});
        details.find('.close').on('click', function(){closePopup("details-visible")});
        details.find('form[name=attributes]').submit('click', function() {uploadFile(); return false;});
        details.find('form[name=access-rights]').submit('click', function() {updateRights(); return false;});
        details.find('form[name=access-rights] .add').on('click', addRightsField);
        details.find('form[name=operations] .delete').on('click', deleteFile);

        // load root folder
        get_folder();

        // set initial layout
        setLayout(storage.layout);
    }

    /*
    * PUBLIC
     */
    this.show_login = show_login;
    this.listPopups = listPopups;

    //show login_screen
    function show_login() {
        addPopup('login-visible');
        $('#login').find('input[name=name]').focus();
    }

    // display the content of the folder with the passed id
    // or reloads current folder if no value is passed
    function get_folder(id) {
        loading(true);

        if(id == undefined) id = storage.current_folder;

        // get content
        api.get_folder(id)
            .done(function( data ) {
                // maybe entered a new folder -> store it
                storage.current_folder = id;
                window.location.hash = id;

                //console.log( data );
                storage.jq_files.html(""); // remove old elements
                $.each(data, function(index, elem) {
                    var keys = Object.keys(elem);
                    var data = "";
                    $.each(keys, function(index, value) {
                        data += " data-" + value + "='" + elem[value] + "'";
                    });
                    var html = "<div class='" + elem['data_type'] + "'" + data + "><span>" + (elem['data_type'] == 'folder' ? elem.name : elem.title) + "</span></div>";

                    storage.jq_files.append(html);
                });
                console.log('finished loading folder ' + id);
                storage.jq_files.find('> div.file').on('click', showFile);
                storage.jq_files.find('> div.folder').on('click', function(event) {
                    get_folder($(event.currentTarget).attr('data-id'));
                });
                $.contextMenu({
                    selector: config.jq_files_id + ' > div.folder',
                    callback: function(key, options) {
                        if(key == 'open') {
                            get_folder($(this).attr('data-id'));
                        }
                        var m = "clicked: " + key + " on " + $(this).text();;
                        console.log("optiona: ");
                        console.log(options);
                        window.console && console.log(m) || alert(m);
                    },
                    items: {
                        "open": {name: "Öffnen", icon: "open"},
                        "sep1": "---------",
                        "rename": {name: "Umbenennen", icon: "rename"},
                        "delete": {name: "Löschen", icon: "delete"}
                    }
                });

            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.error('The folder could not be loaded ' + textStatus);
                console.log(errorThrown);
                showError('Fehler', 'Der Ordnerinhalt konnte nicht geladen werden. Möglicherweise haben Sie keine Rechte');
            })
            .always(function() {
                loading(false);
            });
    }

    function listPopups() {
        console.log(storage.popups);
    }




    /*
     * PRIVATE
     */

    // changes the layout of the page (list or grid layout)
    function setLayout(layout) {
        $(html).removeClass(storage.layout + '-layout');
        $(html).addClass(layout + '-layout');
        storage.layout = layout;
    }

    // open the "new file" dialog
    function newFile() {
        details.addClass('new-file');

        // clear old values
        details.find('h2').html('Neue Datei');
        details.removeClass('image');

        var attribute_form = details.find('form[name=attributes]');
        attribute_form.find('input[name=type]').val('file');
        attribute_form.find('input[name=id]').val('');
        attribute_form.find('input[name=title]').val('');
        attribute_form.find('input[name=folder]').val(storage.current_folder);
        attribute_form.css('display', 'block');

        var access_form = details.find("form[name='access-rights']");
        access_form.css('display', 'none');

        var operations_form = details.find("form[name='operations']");
        operations_form.css('display', 'none');

        addPopup('details-visible');
    }

    // open the "new folder" dialog
    function showNewFolder() {
        // Clear form and prepare for new folder
        var form = newFolder.find('form');
        form.find('input[name=name]').val('');
        form.find('input[name=parent]').val(storage.current_folder);

        // show form and set focus
        $('html').addClass('new-folder-visible');
        form.find('input[name=name]').focus();

        // register popup
        addPopup('new-folder-visible');
    }

    // shows details to the passed file
    function showFile(event) {
        details.removeClass('new-file');
        addPopup('details-visible');

        loading(true);

        var elem = $(event.currentTarget);

        // DISPLAY DATA (READ)
        details.css('top', ($(window).scrollTop() + 10) + 'px');
        details.find('h2').html( elem.attr('data-title') );
        details.find('.hotlink a').attr('href', elem.attr('data-hotlink') );
        details.find('.hotlink a').html(elem.attr('data-hotlink') );
        details.find('.api-link a').attr('href', api.get_file(elem.attr('data-id')) );
        details.find('.api-link a').html(api.get_file(elem.attr('data-id')));
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
            img.attr('src', api.get_file(elem.attr('data-id'), {width: 400, t: new Date().getTime()}));
        } else {
            details.removeClass('image');
        }

        // GET ACCESS RIGHTS
        var admin = false;
        var write = false;
        var read = false;
        api.get_access(elem.attr('data-id'))
            .done(function(data) {
                var access = data['access'];
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
                    attribute_form.find('input[name=id]').val(elem.attr('data-id'));
                    attribute_form.find('input[name=title]').val( elem.attr('data-title') );
                    attribute_form.find('input[name=folder]').val( elem.attr('data-folder') );
                    attribute_form.css('display', 'block');
                } else { // no write rights
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
                    access_form.find('input[name=id]').val(elem.attr('data-id'));

                    // display new access rights
                    api.get_rights(elem.attr('data-id'))
                        .done(function( data ) {
                            $.each(data, function(index, elem) {
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
                        })
                        .always(function() {
                            access_form.removeClass('loading');
                        });
                    access_form.css('display', 'block');
                } else { // no admin rights
                    access_form.css('display', 'none');
                }

                // OPERATIONS (ADMIN)
                var operations_form = details.find("form[name='operations']");
                if(admin) {
                    operations_form.css('display', 'block');
                } else {
                    operations_form.css('display', 'none');
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
        row.remove();
    }

    // creates a new folder with data from the form
    function createNewFolder() {
        // remove focus from from so ENTER will not submit it again
        document.activeElement.blur();
        loading(true);

        api.set_folder(newFolder.find('form').serializeArray())
            .done(function() {
                showSuccess('Gespeichert', 'Der Ordner wurde erfolgreich angelegt')
            })
            .fail(function () {
                showError('Fehler', 'Der Ordner konnte nicht angelegt werden');
            })
            .always(function() {
                loading(false);
                closePopup('new-folder-visible');
                get_folder();
            });
    }


    // uploads the files filled in in the upload form
    // and the set title for it
    function uploadFile() {

        // remove focus from from so ENTER will not submit it again
        document.activeElement.blur();

        loading(true);

        // how to end upload process
        var always = function() {
            storage.selectedFiles = [];
            get_folder();
            loading(false);
            closePopup('details-visible');
        };

        // file ID - will be changed after uploading a file
        var file_id = details.find('input[name=id]').val(); // ID if updating else ''

        // upload a file
        if(storage.selectedFiles.length > 0) {
            var errors = false;
            var set_file_attributes_request = null;
            var set_file_request = api.set_file(file_id, storage.selectedFiles)
                .done(function( data ) {
                    if(file_id != '') { // uploaded a new image -> refresh
                        details.find('img').attr('src', api.get_file(file_id, {width: 400, t: new Date().getTime()}));
                    }
                    file_id = data.id; // Save file ID (possible new file)

                    // set attributes
                    set_file_attributes_request = api.set_file_attributes(file_id, details.find('input[name=title]').val(), details.find('input[name=folder]').val())
                        .fail(function(jqXHR, textStatus, errorThrown) {
                            console.log('JQuery UPLOAD ERROR: ' + textStatus);
                            console.log(errorThrown);
                            errors = true;
                        });
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    console.log('JQuery UPLOAD ERROR: ' + textStatus);
                    console.log(errorThrown);
                    errors = true;
                });
            // When both requests are done -> Give answer to user
            $.when(set_file_request, set_file_attributes_request).always(function() {
                    if(errors) showError('Fehler', 'Die Daten konnten nicht gespeichert werden');
                    else showSuccess('Gespeichert', 'Die Daten wurden gespeichert');
                    always();
                });

        } else if(file_id != '') { // only update attributes of existing file
            // set attributes
            api.set_file_attributes(file_id, details.find('input[name=title]').val(), details.find('input[name=folder]').val())
                .done(function( data ) {
                    if(data['success']) {
                        showSuccess('Gespeichert', 'Die Dateiattribute wurden gespeichert');
                    } else {
                        showError('Fehler', 'Die Anfrage wurde korrekt ausgeführt, allerdings konnten die Daten nicht gesetzt werden.');
                    }
                })
                .fail(function(jqXHR, textStatus, errorThrown) {
                    console.log('JQuery UPLOAD ERROR: ' + textStatus);
                    console.log(errorThrown);
                    showError('Fehler', 'Die Dateiattribute konnten nicht gespeichert werden');
                }).always(always);

        } else { // Upload new file without selecting a file
            loading(false);
            showError('Fehler', 'Um eine neue Datei hoch zu laden, wählen sie eine aus.');
        }
    }

    function updateRights() {

        // remove focus from from so ENTER will not submit it again
        document.activeElement.blur();

        loading(true);
        api.set_rights(details.find('form[name=access-rights]').serialize())
            .done(function() {
                showSuccess('Gespeichert', 'Die Rechte wurden erfolgreich gesetzt');
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.log('Rights could not be set correctly ' + textStatus);
                console.log(errorThrown);
                showError('Fehler', 'Die Rechte konnten nicht gesetzt werden. Der Server hat die Anfrage nicht ausgeführt.');
            })
            .always(function() {
                loading(false);
            });
    }

    function deleteFile() {
        if(!confirm("Diese Datei endgültig löschen?")) return;
        loading(true);
        var file_id = details.find('input[name=id]').val();
        api.delete_file(file_id)
            .done(function() {
                showSuccess('Gelöscht', 'Die Datei wurde gelöscht');
                closePopup("details-visible");
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.log('File could not be deleted correctly ' + textStatus);
                console.log(errorThrown);
                showError('Fehler', 'Die Datei konnte nicht gelöscht werden. Bei der Ausführung trat ein Fehler auf');
            })
            .always(function() {
                get_folder();
                loading(false);
                closePopup('details-visible');
            });
    }

    function folderUp() {
        loading(true);
        api.get_parent_folder()
            .done(function(data, textStatus, jqXHR) {
                if(data['parent'] > 0) {
                    get_folder(data['parent'])
                } else {
                    console.log('Received non valid folder ID as parent');
                    console.log(textStatus);
                    console.log(jqXHR);
                }
            })
            .fail(function(jqXHR, textStatus, errorThrown) {
                console.log('Parent folder could not be read ' + textStatus);
                console.log(errorThrown);
                showError('Fehler', 'Der übergeordnete Ordner konnte nicht abgefragt werden. Möglicherweise haben Sie keine Rechte.');
            })
            .always(function() {
                loading(false);
            });
    }

    function showSuccess(title, content, time) {
        if(storage.noMessages) return;
        if(time === undefined) time = 5000;
        success.find('h2').html(title);
        success.find('p').html(content);
        addPopup('success-visible');
        success.focus();
        setTimeout(function() {
            closePopup('success-visible');
        }, time);
    }

    function showError(title, content, time) {
        if(storage.noMessages) return;
        if(time === undefined) time = 10000;
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
        $('.block.scrollable').css('top', $(window).scrollTop() + $(window).height() * 0.1);
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

    var api = {
        get_current_user: function() {
            return $.ajax({
                url: 'api.php?q=get_current_user',
                type: 'GET',
                dataType: 'json'
            });
        },

        get_file: function (id, options) {
            var final = 'http://cloud.salzhimmel.de/api.php?q=get_file&id=' + id;
            if(options != undefined && options != null && options != {}) {
                options = {w: options};
                final += "&" + $.param(options);
            }

            return final;
        },

        set_file: function (id, selected_files) {
            var file_data = new FormData();
            file_data.append('id', id);
            $.each(selected_files, function (key, value) {
                file_data.append(key, value);
            });
            return $.ajax({
                url: 'api.php?q=set_file',
                type: 'POST',
                data: file_data,
                cache: false,
                dataType: 'json',
                processData: false, // Don't process the files
                contentType: false // Set content type to false as jQuery will tell the server its a query string request
            })
        },

        delete_file: function (id) {
            return $.ajax({
                url: 'api.php?q=delete_file&id=' + id,
                type: 'GET',
                dataType: 'json'
            });
        },

        get_file_attributes: function (id) {
            return $.ajax({
                url: 'api.php?q=get_file_attributes&v=' + id,
                type: 'GET',
                dataType: 'json'
            });
        },

        set_file_attributes: function (id, title, folder) {
            var options = {w: {
                title: title,
                folder: folder
            }};
            return $.ajax({
                url: 'api.php?q=set_file_attributes&v=' + id + '&' + $.param(options),
                type: 'POST',
                dataType: 'json'
            });
        },

        get_access: function (id) {
            return $.ajax({
                url: 'api.php?q=get_access&v=' + id + '&w=file',
                type: 'GET',
                dataType: 'json'
            });
        },

        get_rights: function (id) {
            return $.ajax({
                url: 'api.php?q=get_rights&v=' + id + '&w=file',
                type: 'GET',
                dataType: 'json'
            });
        },

        set_rights: function (data) {
            return $.ajax({
                url: 'api.php?q=set_rights',
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        },

        set_folder: function (data) {
            return $.ajax({
                url: 'api.php?q=set_folder',
                type: 'POST',
                data: data,
                dataType: 'json'
            });
        },

        get_folder: function (folder) {
            return $.ajax({
                url: 'api.php?q=get_folder&v=' + folder,
                type: 'GET',
                dataType: 'json'
            });
        },

        get_parent_folder: function (folder) {
            if(folder == undefined) folder = storage.current_folder;
            return $.ajax({
                url: 'api.php?q=get_parent_folder&id=' + folder,
                type: 'GET',
                dataType: 'json'
            });
        }
    };

    init();

});