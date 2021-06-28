var select_alma_fee_plan_ids = select_alma_fee_plan_ids || [];
jQuery(document).ready(function() {
    select_alma_fee_plan_ids.forEach(function(id) {
        if (["number", "string"].includes(typeof id) && !!String(id).trim()) {
            jQuery('#' + id).change(function () {
                jQuery('.alma_fee_plan').hide();
                var $sections = jQuery('.alma_fee_plan_' + jQuery(this).val());
                $sections.show();
                $sections.effect('highlight', 1500);
                $sections.find('b').effect('highlight', 5000);
            });
        }
    })
});
