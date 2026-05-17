{#
  File-manager view for os-netboot.

  Three primary actions:
    1. Bootstrap netboot.xyz -- fetches both .kpxe (BIOS) and .efi (UEFI)
       binaries in one server-side call. This is the prominent first-run
       action -- it makes the plugin do the right thing for both firmware
       types without the user picking one.
    2. Fetch from URL -- server-side download of an arbitrary URL into
       the current directory. For things outside netboot.xyz.
    3. Drag-and-drop / file picker upload -- browser-side push.

  Table shows files and subdirectories with delete and download buttons.

  All operations call Api/FilesController. Paths from the client are
  treated as untrusted there.
#}

<style>
    .netboot-dropzone {
        border: 2px dashed #b0b0b0;
        border-radius: 6px;
        padding: 30px;
        text-align: center;
        color: #777;
        margin-bottom: 12px;
        transition: background 0.15s ease-in;
    }
    .netboot-dropzone.dragover {
        background: #f0f8ff;
        border-color: #3071a9;
        color: #3071a9;
    }
    .netboot-table .file-size {
        white-space: nowrap;
        font-variant-numeric: tabular-nums;
        text-align: right;
    }
    .netboot-table .file-actions {
        text-align: right;
        white-space: nowrap;
    }
    .netboot-pathbar {
        font-family: monospace;
        background: #f5f5f5;
        padding: 6px 10px;
        border-radius: 4px;
        margin-bottom: 8px;
    }
</style>

<section class="page-content-main">
    <div class="content-box __mb">
        <div class="content-box-main">

            <h2>{{ lang._('Quick start') }}</h2>
            <p>
                {{ lang._('Click the button below once to fetch both the legacy-BIOS and UEFI x86_64 iPXE bootstrap binaries from boot.netboot.xyz into the content root. After this every PXE-booted machine on your network -- regardless of firmware -- can reach the netboot.xyz menu.') }}
            </p>
            <button id="bootstrapBtn" class="btn btn-primary">
                <i class="fa fa-cloud-download" aria-hidden="true"></i>
                {{ lang._('Bootstrap netboot.xyz (BIOS + UEFI)') }}
                <i id="bootstrapBtn_progress" class=""></i>
            </button>
            <pre id="bootstrapOutput" style="display:none; margin-top: 12px;"></pre>

            <hr/>

            <h2>{{ lang._('Files') }}</h2>
            <div class="netboot-pathbar">
                <i class="fa fa-folder-open"></i>
                <span id="currentPathDisplay">/</span>
            </div>

            <div id="dropzone" class="netboot-dropzone">
                {{ lang._('Drop files here to upload, or') }}
                <input type="file" id="uploadPicker" multiple style="display:none;"/>
                <button id="pickUploadBtn" class="btn btn-default btn-sm">{{ lang._('pick files') }}</button>
            </div>

            <div style="margin-bottom:10px;">
                <button id="fetchUrlBtn" class="btn btn-default btn-sm">
                    <i class="fa fa-link"></i>
                    {{ lang._('Fetch from URL...') }}
                </button>
                <button id="mkdirBtn" class="btn btn-default btn-sm">
                    <i class="fa fa-folder-o"></i>
                    {{ lang._('New folder...') }}
                </button>
                <button id="refreshBtn" class="btn btn-default btn-sm">
                    <i class="fa fa-refresh"></i>
                    {{ lang._('Refresh') }}
                </button>
            </div>

            <table class="table table-striped netboot-table">
                <thead>
                    <tr>
                        <th>{{ lang._('Name') }}</th>
                        <th class="file-size">{{ lang._('Size') }}</th>
                        <th>{{ lang._('Modified') }}</th>
                        <th class="file-actions">{{ lang._('Actions') }}</th>
                    </tr>
                </thead>
                <tbody id="fileTableBody">
                </tbody>
            </table>
        </div>
    </div>
</section>

<script>
    var currentPath = '';

    function humanSize(bytes) {
        if (bytes === 0) return '';
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var i = 0;
        while (bytes >= 1024 && i < units.length - 1) {
            bytes /= 1024.0; i++;
        }
        return bytes.toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
    }

    function loadDir(path) {
        currentPath = path;
        $('#currentPathDisplay').text('/' + path);
        $.getJSON('/api/netboot/files/list', {path: path}, function (data) {
            var $tb = $('#fileTableBody').empty();
            if (data.status !== 'ok') {
                $tb.append('<tr><td colspan="4" class="text-danger">' +
                    (data.message || 'Failed to list directory') + '</td></tr>');
                return;
            }
            if (path !== '') {
                var up = path.replace(/\/[^\/]+\/?$/, '');
                $tb.append(
                    '<tr><td colspan="4"><a href="#" data-go="' + up + '">.. (parent)</a></td></tr>'
                );
            }
            $.each(data.entries, function (_i, e) {
                var full = (path === '' ? '' : path + '/') + e.name;
                var nameCell = e.is_dir
                    ? '<i class="fa fa-folder text-warning"></i> <a href="#" data-go="' + full + '">' + e.name + '</a>'
                    : '<i class="fa fa-file-o"></i> ' + e.name;
                var actions =
                    (!e.is_dir
                        ? '<a class="btn btn-default btn-xs" href="/api/netboot/files/download?path=' + encodeURIComponent(full) + '" title="Download"><i class="fa fa-download"></i></a> '
                        : '') +
                    '<button class="btn btn-danger btn-xs" data-delete="' + full + '" title="Delete"><i class="fa fa-trash"></i></button>';
                $tb.append(
                    '<tr>' +
                    '<td>' + nameCell + '</td>' +
                    '<td class="file-size">' + humanSize(e.size) + '</td>' +
                    '<td>' + new Date(e.mtime * 1000).toLocaleString() + '</td>' +
                    '<td class="file-actions">' + actions + '</td>' +
                    '</tr>'
                );
            });
            $('#fileTableBody [data-go]').click(function (ev) {
                ev.preventDefault();
                loadDir($(this).data('go'));
            });
            $('#fileTableBody [data-delete]').click(function () {
                var p = $(this).data('delete');
                if (!confirm('Delete ' + p + '?')) return;
                $.post('/api/netboot/files/delete', {path: p}, function (r) {
                    if (r.status !== 'ok') alert(r.message || 'Delete failed');
                    loadDir(currentPath);
                });
            });
        });
    }

    function uploadFiles(fileList) {
        var fd = new FormData();
        fd.append('path', currentPath);
        for (var i = 0; i < fileList.length; i++) {
            fd.append('file' + i, fileList[i]);
        }
        $.ajax({
            url: '/api/netboot/files/upload',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            success: function () { loadDir(currentPath); }
        });
    }

    $(document).ready(function () {
        loadDir('');

        $('#refreshBtn').click(function () { loadDir(currentPath); });

        $('#pickUploadBtn').click(function () { $('#uploadPicker').click(); });
        $('#uploadPicker').change(function () { uploadFiles(this.files); });

        var $dz = $('#dropzone');
        $dz.on('dragover', function (e) { e.preventDefault(); $dz.addClass('dragover'); });
        $dz.on('dragleave', function () { $dz.removeClass('dragover'); });
        $dz.on('drop', function (e) {
            e.preventDefault(); $dz.removeClass('dragover');
            uploadFiles(e.originalEvent.dataTransfer.files);
        });

        $('#mkdirBtn').click(function () {
            var name = prompt('New folder name?');
            if (!name) return;
            var p = (currentPath === '' ? '' : currentPath + '/') + name;
            $.post('/api/netboot/files/mkdir', {path: p}, function (r) {
                if (r.status !== 'ok') alert(r.message || 'mkdir failed');
                loadDir(currentPath);
            });
        });

        $('#fetchUrlBtn').click(function () {
            var url = prompt('URL to fetch (http/https):');
            if (!url) return;
            $.post('/api/netboot/files/fetch_url',
                   {url: url, path: currentPath, name: ''},
                   function (r) {
                       if (r.status !== 'ok') alert(r.message || 'Fetch failed');
                       loadDir(currentPath);
                   });
        });

        $('#bootstrapBtn').click(function () {
            $('#bootstrapBtn').prop('disabled', true);
            $('#bootstrapBtn_progress').addClass('fa fa-spinner fa-pulse');
            $('#bootstrapOutput').hide().text('');
            ajaxCall(url = '/api/netboot/service/bootstrapNetbootXyz',
                     sendData = {},
                     callback = function (data) {
                         $('#bootstrapBtn').prop('disabled', false);
                         $('#bootstrapBtn_progress').removeClass('fa fa-spinner fa-pulse');
                         if (data && data.output) {
                             $('#bootstrapOutput').show().text(data.output);
                         }
                         loadDir(currentPath);
                     });
        });
    });
</script>
