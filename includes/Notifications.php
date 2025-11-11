<?php
namespace AgentHub;

class Notifications {
    /**
     * Send batch completion email
     */
    public static function send_completion_email($batch_id) {
        global $wpdb;
        
        $jobs_table = $wpdb->prefix . '402links_batch_jobs';
        
        $job = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM $jobs_table WHERE batch_id = %s
        ", $batch_id), ARRAY_A);
        
        if (!$job || $job['notification_sent']) {
            return false;
        }
        
        $user = get_user_by('id', $job['user_id']);
        if (!$user) {
            return false;
        }
        
        $to = $job['notification_email'] ?: $user->user_email;
        $subject = self::get_email_subject($job);
        $message = self::get_email_body($job, $user);
        $headers = ['Content-Type: text/html; charset=UTF-8'];
        
        $sent = wp_mail($to, $subject, $message, $headers);
        
        if ($sent) {
            // Mark notification as sent
            $wpdb->update($jobs_table, 
                ['notification_sent' => 1, 'updated_at' => current_time('mysql')],
                ['batch_id' => $batch_id]
            );
            
            error_log("📧 [Notifications] Sent completion email for batch {$batch_id} to {$to}");
        } else {
            error_log("🔴 [Notifications] Failed to send email for batch {$batch_id}");
        }
        
        return $sent;
    }
    
    /**
     * Get email subject based on batch status
     */
    private static function get_email_subject($job) {
        $total = $job['total_posts'];
        $completed = $job['completed_posts'];
        $failed = $job['failed_posts'];
        
        if ($failed == 0) {
            return "✅ Batch Complete: {$completed} Links Generated Successfully";
        } elseif ($completed == 0) {
            return "⚠️ Batch Failed: {$failed} Links Could Not Be Generated";
        } else {
            return "⚠️ Batch Partial Success: {$completed} Generated, {$failed} Failed";
        }
    }
    
    /**
     * Get HTML email body
     */
    private static function get_email_body($job, $user) {
        $site_name = get_bloginfo('name');
        $admin_url = admin_url('admin.php?page=agent-hub');
        
        $total = $job['total_posts'];
        $completed = $job['completed_posts'];
        $created = $job['created_posts'];
        $updated = $job['updated_posts'];
        $failed = $job['failed_posts'];
        
        $success_rate = $total > 0 ? round(($completed / $total) * 100, 1) : 0;
        
        $status_color = $failed == 0 ? '#10b981' : ($completed == 0 ? '#ef4444' : '#f59e0b');
        $status_emoji = $failed == 0 ? '✅' : ($completed == 0 ? '❌' : '⚠️');
        
        $duration = '';
        if ($job['started_at'] && $job['completed_at']) {
            $start = strtotime($job['started_at']);
            $end = strtotime($job['completed_at']);
            $seconds = $end - $start;
            if ($seconds >= 60) {
                $minutes = floor($seconds / 60);
                $duration = "{$minutes} minute" . ($minutes != 1 ? 's' : '');
            } else {
                $duration = "{$seconds} second" . ($seconds != 1 ? 's' : '');
            }
        }
        
        ob_start();
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="margin: 0; padding: 0; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;">
            <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f3f4f6; padding: 40px 20px;">
                <tr>
                    <td align="center">
                        <table width="600" cellpadding="0" cellspacing="0" style="background-color: #ffffff; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                            <!-- Header -->
                            <tr>
                                <td style="padding: 40px 40px 20px; text-align: center; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 8px 8px 0 0;">
                                    <h1 style="margin: 0; color: #ffffff; font-size: 28px; font-weight: 700;">
                                        <?php echo $status_emoji; ?> Batch Generation Complete
                                    </h1>
                                </td>
                            </tr>
                            
                            <!-- Content -->
                            <tr>
                                <td style="padding: 40px;">
                                    <p style="margin: 0 0 20px; color: #374151; font-size: 16px; line-height: 1.5;">
                                        Hi <?php echo esc_html($user->display_name); ?>,
                                    </p>
                                    
                                    <p style="margin: 0 0 30px; color: #374151; font-size: 16px; line-height: 1.5;">
                                        Your batch link generation job has completed on <strong><?php echo esc_html($site_name); ?></strong>.
                                    </p>
                                    
                                    <!-- Stats Box -->
                                    <table width="100%" cellpadding="0" cellspacing="0" style="background-color: #f9fafb; border-radius: 6px; border: 1px solid #e5e7eb; margin-bottom: 30px;">
                                        <tr>
                                            <td style="padding: 20px;">
                                                <table width="100%" cellpadding="0" cellspacing="0">
                                                    <tr>
                                                        <td style="padding: 10px 0;">
                                                            <span style="color: #6b7280; font-size: 14px;">Batch ID:</span><br>
                                                            <strong style="color: #111827; font-size: 16px; font-family: monospace;"><?php echo esc_html($job['batch_id']); ?></strong>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 10px 0; border-top: 1px solid #e5e7eb;">
                                                            <span style="color: #6b7280; font-size: 14px;">Total Posts:</span>
                                                            <strong style="color: #111827; font-size: 20px; margin-left: 10px;"><?php echo $total; ?></strong>
                                                        </td>
                                                    </tr>
                                                    <tr>
                                                        <td style="padding: 10px 0; border-top: 1px solid #e5e7eb;">
                                                            <span style="color: #6b7280; font-size: 14px;">Successfully Completed:</span>
                                                            <strong style="color: #10b981; font-size: 20px; margin-left: 10px;"><?php echo $completed; ?></strong>
                                                            <span style="color: #6b7280; font-size: 14px;">(<?php echo $created; ?> created, <?php echo $updated; ?> updated)</span>
                                                        </td>
                                                    </tr>
                                                    <?php if ($failed > 0): ?>
                                                    <tr>
                                                        <td style="padding: 10px 0; border-top: 1px solid #e5e7eb;">
                                                            <span style="color: #6b7280; font-size: 14px;">Failed:</span>
                                                            <strong style="color: #ef4444; font-size: 20px; margin-left: 10px;"><?php echo $failed; ?></strong>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                    <tr>
                                                        <td style="padding: 10px 0; border-top: 1px solid #e5e7eb;">
                                                            <span style="color: #6b7280; font-size: 14px;">Success Rate:</span>
                                                            <strong style="color: <?php echo $status_color; ?>; font-size: 20px; margin-left: 10px;"><?php echo $success_rate; ?>%</strong>
                                                        </td>
                                                    </tr>
                                                    <?php if ($duration): ?>
                                                    <tr>
                                                        <td style="padding: 10px 0; border-top: 1px solid #e5e7eb;">
                                                            <span style="color: #6b7280; font-size: 14px;">Duration:</span>
                                                            <strong style="color: #111827; font-size: 16px; margin-left: 10px;"><?php echo $duration; ?></strong>
                                                        </td>
                                                    </tr>
                                                    <?php endif; ?>
                                                </table>
                                            </td>
                                        </tr>
                                    </table>
                                    
                                    <?php if ($failed > 0): ?>
                                    <p style="margin: 0 0 20px; color: #b91c1c; font-size: 14px; background-color: #fef2f2; padding: 12px; border-radius: 6px; border-left: 4px solid #ef4444;">
                                        <strong>⚠️ Note:</strong> Some posts failed to generate links. You can retry failed posts from the batch history in your admin dashboard.
                                    </p>
                                    <?php endif; ?>
                                    
                                    <!-- CTA Button -->
                                    <table width="100%" cellpadding="0" cellspacing="0" style="margin-top: 30px;">
                                        <tr>
                                            <td align="center">
                                                <a href="<?php echo esc_url($admin_url); ?>" style="display: inline-block; padding: 14px 32px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 16px;">
                                                    View Dashboard
                                                </a>
                                            </td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>
                            
                            <!-- Footer -->
                            <tr>
                                <td style="padding: 20px 40px; background-color: #f9fafb; border-top: 1px solid #e5e7eb; border-radius: 0 0 8px 8px;">
                                    <p style="margin: 0; color: #6b7280; font-size: 14px; text-align: center;">
                                        Sent by Tolliver Agent Hub on <?php echo esc_html($site_name); ?>
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        <?php
        
        return ob_get_clean();
    }
}
