(function($, pp){

    $(document).ready(function(){
        init();
    });

    function init() {
        $("input[name='pp-log-type'], #pp-log-actions label").on("click", function(e){
            var radio   = $(this).prop("tagName") == "LABEL" ? $(this).parent() : $(this);
            var type    = radio.val();
            if(type !== "all") {
                $("#pp-log-console .pp-log").hide();
                $("#pp-log-console .pp-log-" + type).show();
            } else {
                $("#pp-log-console .pp-log").show();
            }
        });

        $("#pp-download").on("click", function(e){
            e.preventDefault();
            showSpinner();
            $.ajax({
                url: ajaxurl,
                method: "post",
                data: {
                    "action"        : "parrot",
                    "_action"       : "download_logs",
                    "nonce"         : pp.nonce,
                    "plugin_name"   : $('#pp_plugin_name').val()
                },
                success: function (data, textStatus, jqXHR) {
                    var a = document.createElement("a");
                    document.body.appendChild(a);
                    a.style = "display: none";
                    var blob = new Blob([data.data.csv], {type: "application/csv"}),
                        url = window.URL.createObjectURL(blob);
                    a.href = url;
                    a.download = data.data.name;
                    a.click();
                    setTimeout(function () {
                        window.URL.revokeObjectURL(url);
                    }, 100);
                },
                complete: function () {
                    hideSpinner();
                }
            });
        });
    }

    function showSpinner() {
        $('#pp-spinner').css('visibility', 'visible').attr('aria-hidden', 'false').show();
    }

    function hideSpinner() {
        $('#pp-spinner').css('visibility', 'hidden').attr('aria-hidden', 'true').hide();
    }

})(jQuery, pp);
