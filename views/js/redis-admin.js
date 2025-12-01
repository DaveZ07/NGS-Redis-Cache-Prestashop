$(document).ready(function() {
    function toggleRedisFields() {
        var connectionType = $('select[name="NGS_REDIS_CONNECTION_TYPE"]').val();
        
        if (connectionType === 'sentinel') {
            $('.single_field').closest('.form-group').hide();
            $('.sentinel_field').closest('.form-group').show();
            $('.cluster_field').closest('.form-group').hide();
        } else if (connectionType === 'cluster') {
            $('.single_field').closest('.form-group').hide();
            $('.sentinel_field').closest('.form-group').hide();
            $('.cluster_field').closest('.form-group').show();
        } else {
            $('.single_field').closest('.form-group').show();
            $('.sentinel_field').closest('.form-group').hide();
            $('.cluster_field').closest('.form-group').hide();
        }
    }

    // Initial run
    toggleRedisFields();

    // On change
    $('select[name="NGS_REDIS_CONNECTION_TYPE"]').change(function() {
        toggleRedisFields();
    });
});
