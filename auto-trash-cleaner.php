<?php
/*
Plugin Name: Auto Trash Cleaner with Working Progress Bar
Description: Deletes items from the post trash bin with accurate progress tracking.
Version: 1.7.2
Author: Modacity
*/

// Register activation and deactivation hooks
register_activation_hook(__FILE__, 'atc_initialize_plugin');
register_deactivation_hook(__FILE__, 'atc_deactivate');

function atc_initialize_plugin() {
    // Set default options
    add_option('atc_enable_auto_clean', false);
    add_option('atc_last_run', 'Never');
    add_option('atc_total_trash', 0); // Initialize total trash count
    
    // Setup WordPress cron
    if (!wp_next_scheduled('atc_custom_event')) {
        wp_schedule_event(time(), 'atc_custom_interval', 'atc_custom_event');
    }
    
    // Add notice about real cron setup
    add_action('admin_notices', 'atc_cron_notice');
}

function atc_deactivate() {
    wp_clear_scheduled_hook('atc_custom_event');
}

// Display cron setup notice
function atc_cron_notice() {
    if (!defined('DISABLE_WP_CRON') || !DISABLE_WP_CRON) {
        echo '<div class="notice notice-warning"><p>For reliable trash cleaning, please add this to your wp-config.php: <code>define(\'DISABLE_WP_CRON\', true);</code> and set up a real cron job to run: <code>wget -q -O - '.site_url('wp-cron.php').' >/dev/null 2>&1</code> every 5 minutes.</p></div>';
    }
}

// Hook for the custom scheduled event
add_action('atc_custom_event', 'atc_delete_trash_items');

// Function to count trash items - more efficient query
function atc_count_trash_items() {
    global $wpdb;
    return (int)$wpdb->get_var(
        "SELECT COUNT(*) FROM $wpdb->posts WHERE post_status = 'trash'"
    );
}

// Function to delete trash items
function atc_delete_trash_items() {
    if (!get_option('atc_enable_auto_clean', false)) return;

    // Set initial total count if not set
    if (0 === (int)get_option('atc_total_trash', 0)) {
        update_option('atc_total_trash', atc_count_trash_items());
    }

    $delete_limit = 250;
    $deleted = 0;
    
    while ($deleted < $delete_limit) {
        $post_ids = get_posts(array(
            'post_status' => 'trash',
            'posts_per_page' => 1,
            'fields' => 'ids',
            'post_type' => 'any',
            'suppress_filters' => true
        ));

        if (empty($post_ids)) break;

        if (wp_delete_post($post_ids[0], true)) {
            $deleted++;
        }
    }

    update_option('atc_last_run', current_time('mysql').' (Deleted '.$deleted.' items)');
    
    // If trash is empty, reset total count and disable
    if (0 === atc_count_trash_items()) {
        update_option('atc_total_trash', 0);
        update_option('atc_enable_auto_clean', false);
    } else {
        // Schedule next run if more items exist
        wp_schedule_single_event(time() + 60, 'atc_custom_event');
    }
}

// Add the custom interval
add_filter('cron_schedules', 'atc_add_custom_interval');

function atc_add_custom_interval($schedules) {
    $schedules['atc_custom_interval'] = array(
        'interval' => 1500,
        'display'  => __('Every 25 Minutes')
    );
    return $schedules;
}

// Add the settings page
add_action('admin_menu', 'atc_add_settings_page');

function atc_add_settings_page() {
    add_options_page('Auto Trash Cleaner Settings', 'Auto Trash Cleaner', 'manage_options', 'atc-settings', 'atc_settings_page');
}

// Settings page content
function atc_settings_page() {
    if (isset($_POST['atc_manual_trigger']) && check_admin_referer('atc_manual_trigger')) {
        // Reset total count when manually triggering
        update_option('atc_total_trash', atc_count_trash_items());
        do_action('atc_custom_event');
        add_settings_error('atc_messages', 'atc_message', __('Manual cleanup triggered.'), 'updated');
    }
    
    settings_errors('atc_messages');
    ?>
    <div class="wrap">
        <h1>Auto Trash Cleaner Settings</h1>
        
        <div class="card widefat">
            <h2 class="title">Current Status</h2>
            <table class="form-table" role="presentation">
                <tbody>
                    <tr>
                        <th scope="row">Auto Clean Status</th>
                        <td>
                            <span id="atc-status-indicator" class="atc-status-indicator">
                                <?php echo get_option('atc_enable_auto_clean') ? 'ACTIVE' : 'INACTIVE'; ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Last Run</th>
                        <td id="atc-last-run"><?php echo get_option('atc_last_run', 'Never'); ?></td>
                    </tr>
                    <tr>
                        <th scope="row">Next Scheduled Run</th>
                        <td id="atc-next-run">
                            <?php echo wp_next_scheduled('atc_custom_event') ? date('Y-m-d H:i:s', wp_next_scheduled('atc_custom_event')) : 'Not scheduled'; ?>
                        </td>
                    </tr>
                </tbody>
            </table>
            
            <div class="atc-progress-section">
                <h2>Cleanup Progress</h2>
                <div id="atc-progress-container">
                    <div class="progress-labels">
                        <strong id="atc-progress-text">0 of 0 items processed</strong>
                        <strong id="atc-progress-percent">0%</strong>
                    </div>
                    <div id="atc-progress-bar-container">
                        <div id="atc-progress-bar"></div>
                    </div>
                </div>
                <div id="atc-no-trash" class="notice notice-success" style="display:none;">
                    <p>✅ Trash bin is completely empty!</p>
                </div>
                
                <form method="post" class="atc-manual-trigger">
                    <?php wp_nonce_field('atc_manual_trigger'); ?>
                    <input type="hidden" name="atc_manual_trigger" value="1">
                    <?php submit_button('Run Cleanup Now', 'primary large', 'submit', false); ?>
                </form>
            </div>
        </div>
        
        <div class="card widefat">
            <h2 class="title">Settings</h2>
            <form method="post" action="options.php">
                <?php
                settings_fields('atc_settings_group');
                do_settings_sections('atc-settings');
                submit_button('Save Settings', 'primary large');
                ?>
            </form>
        </div>
    </div>
    
    <style>
        .card.widefat {
            max-width: 100%;
            padding: 20px;
            margin-bottom: 20px;
            background: #fff;
            border: 1px solid #c3c4c7;
            box-shadow: 0 1px 1px rgba(0,0,0,0.04);
        }
        .card .title {
            margin-top: 0;
            padding-top: 0;
            border-bottom: 1px solid #dcdcde;
            padding-bottom: 12px;
        }
        .atc-status-indicator {
            font-weight: 600;
            padding: 4px 8px;
            border-radius: 3px;
            background-color: rgba(70, 180, 80, 0.1);
            color: #46b450;
        }
        .atc-status-indicator.inactive {
            color: #dc3232;
            background-color: rgba(220, 50, 50, 0.1);
        }
        .atc-progress-section {
            margin: 2em 0;
        }
        .progress-labels {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        #atc-progress-bar-container {
            height: 20px;
            border-radius: 3px;
            overflow: hidden;
            width: 100%;
            border: 1px solid #c3c4c7;
            background: #f0f0f1;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
        }
        #atc-progress-bar {
            background: linear-gradient(to right, #46b450, #7ad03a);
            height: 100%;
            width: 0%;
            transition: width 0.5s ease;
        }
        .atc-manual-trigger {
            margin-top: 1.5em;
        }
    </style>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Get fresh progress data
        function updateProgress() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'atc_get_progress',
                    timestamp: new Date().getTime() // Prevent caching
                },
                success: function(response) {
                    if (response.success) {
                        var total = response.data.total_trash;
                        var current = response.data.current_trash;
                        var processed = total - current;
                        var percent = total > 0 ? Math.round((processed / total) * 100) : 100;
                        
                        // Update display
                        $('#atc-progress-text').text(processed + ' of ' + total + ' items processed');
                        $('#atc-progress-percent').text(percent + '%');
                        $('#atc-progress-bar').css('width', percent + '%');
                        
                        // Toggle visibility
                        if (current <= 0 && total > 0) {
                            $('#atc-progress-container').hide();
                            $('#atc-no-trash').show();
                        } else {
                            $('#atc-progress-container').show();
                            $('#atc-no-trash').hide();
                        }
                    }
                },
                error: function() {
                    setTimeout(updateProgress, 3000);
                }
            });
        }
        
        // Update status information
        function updateStatus() {
            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'atc_get_status'
                },
                success: function(response) {
                    if (response.success) {
                        var indicator = $('#atc-status-indicator');
                        indicator
                            .text(response.data.enabled ? 'ACTIVE' : 'INACTIVE')
                            .toggleClass('inactive', !response.data.enabled);
                        
                        $('#atc-last-run').text(response.data.last_run);
                        
                        var next_run = response.data.next_run ? 
                            new Date(response.data.next_run * 1000).toLocaleString() : 
                            'Not scheduled';
                        $('#atc-next-run').text(next_run);
                    }
                }
            });
        }
        
        // Initial load
        updateProgress();
        updateStatus();
        
        // Set up regular updates
        setInterval(updateProgress, 3000);
        setInterval(updateStatus, 10000);
        
        // Update status when toggling checkbox
        $('input[name="atc_enable_auto_clean"]').change(function() {
            updateStatus();
        });
    });
    </script>
    <?php
}

// Register the settings
add_action('admin_init', 'atc_register_settings');

function atc_register_settings() {
    register_setting('atc_settings_group', 'atc_enable_auto_clean');
    add_settings_section('atc_main_section', 'Main Settings', 'atc_main_section_callback', 'atc-settings');
    add_settings_field('atc_enable_auto_clean', 'Enable Auto Clean', 'atc_enable_auto_clean_callback', 'atc-settings', 'atc_main_section');
}

function atc_main_section_callback() {
    echo '<p>Automatically empty the trash bin every 25 minutes when enabled.</p>';
}

function atc_enable_auto_clean_callback() {
    $is_enabled = get_option('atc_enable_auto_clean', false);
    echo '<label><input type="checkbox" name="atc_enable_auto_clean" value="1" ' . checked($is_enabled, true, false) . '> Enable automatic trash cleaning</label>';
    echo '<p class="description">When enabled, the plugin will empty the trash every 25 minutes until empty.</p>';
}

// AJAX handler for getting progress
add_action('wp_ajax_atc_get_progress', 'atc_get_progress');
function atc_get_progress() {
    $total = (int)get_option('atc_total_trash', 0);
    $current = atc_count_trash_items();
    
    // If total was never set (manual trigger case), use current as total
    if ($total === 0 && $current > 0) {
        $total = $current;
        update_option('atc_total_trash', $total);
    }
    
    wp_send_json_success(array(
        'total_trash' => $total,
        'current_trash' => $current
    ));
}

// AJAX handler for getting status
add_action('wp_ajax_atc_get_status', 'atc_get_status');
function atc_get_status() {
    wp_send_json_success(array(
        'enabled' => get_option('atc_enable_auto_clean', false),
        'last_run' => get_option('atc_last_run', 'Never'),
        'next_run' => wp_next_scheduled('atc_custom_event')
    ));
}