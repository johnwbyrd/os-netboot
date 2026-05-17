{#
  General settings view for os-netboot.

  The form fields are described in mvc/app/controllers/OPNsense/Netboot/forms/general.xml.
  This view just glues them to the API:

    - mapDataToFormUI()      pulls current values from /api/netboot/general/get
    - saveFormToEndpoint()   posts the edits to /api/netboot/general/set
    - on save success we then ping /api/netboot/service/reconfigure, which
      re-renders templates and restarts the affected daemons.

  Service status indicator at the top of the page is the standard OPNsense
  service-controller partial pointing at our ServiceController.
#}

<script>
    $(document).ready(function() {
        var data_get_map = {'frm_general_settings': "/api/netboot/general/get"};

        mapDataToFormUI(data_get_map).done(function (data) {
            formatTokenizersUI();
            $('.selectpicker').selectpicker('refresh');
        });

        // updateServiceControlUI is provided by OPNsense's base UI scripts.
        // Wires up start/stop/restart buttons and status badge for our
        // ServiceController.
        updateServiceControlUI('netboot');

        $("#saveAct").click(function () {
            saveFormToEndpoint(url = "/api/netboot/general/set",
                               formid = 'frm_general_settings',
                               callback_ok = function () {
                                   $("#saveAct_progress").addClass("fa-spinner fa-pulse");
                                   ajaxCall(url = "/api/netboot/service/reconfigure",
                                            sendData = {},
                                            callback = function () {
                                                $("#saveAct_progress").removeClass("fa-spinner fa-pulse");
                                                updateServiceControlUI('netboot');
                                            });
                               });
        });
    });
</script>

<div class="content-box __mb">
    {{ partial("layout_partials/base_form",['fields':generalForm,'id':'frm_general_settings']) }}
</div>

<section class="page-content-main">
    <div class="content-box">
        <div class="col-md-12">
            <br/>
            <button class="btn btn-primary" id="saveAct" type="button">
                <b>{{ lang._('Save') }}</b> <i id="saveAct_progress"></i>
            </button>
            <br/><br/>
        </div>
    </div>
</section>
