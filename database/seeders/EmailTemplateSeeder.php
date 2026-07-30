<?php

namespace Database\Seeders;

use App\Models\EmailTemplate;
use Illuminate\Database\Seeder;

class EmailTemplateSeeder extends Seeder
{
    public function run(): void
    {
        EmailTemplate::updateOrCreate(
            ['slug' => 'reporter-ack'],
            [
                'name' => 'Reporter Acknowledgement',
                'type' => 'reporter_ack',
                'subject' => 'Abuse Report Received — {{case_number}}',
                'body_html' => '<p>Dear {{reporter_name}},</p><p>Thank you for your abuse report. We have opened case <strong>{{case_number}}</strong> and our team is investigating.</p><p>You can check the status of your report using your case reference number.</p><p>Regards,<br>Abuse AI Team</p>',
                'body_text' => "Dear {{reporter_name}},\n\nThank you for your abuse report. We have opened case {{case_number}} and our team is investigating.\n\nYou can check the status of your report using your case reference number.\n\nRegards,\nAbuse AI Team",
                'variables' => ['reporter_name', 'reporter_email', 'case_number', 'abuse_type', 'target_ip'],
                'is_active' => true,
            ],
        );

        EmailTemplate::updateOrCreate(
            ['slug' => 'reporter-status-update'],
            [
                'name' => 'Reporter Status Update',
                'type' => 'reporter_status',
                'subject' => 'Case Update — {{case_number}}',
                'body_html' => '<p>Dear {{reporter_name}},</p><p>We are writing to update you on case <strong>{{case_number}}</strong>.</p><p>Current status: <strong>{{status}}</strong></p><p>{{update_message}}</p><p>Regards,<br>Abuse AI Team</p>',
                'body_text' => "Dear {{reporter_name}},\n\nWe are writing to update you on case {{case_number}}.\n\nCurrent status: {{status}}\n\n{{update_message}}\n\nRegards,\nAbuse AI Team",
                'variables' => ['reporter_name', 'case_number', 'status', 'update_message'],
                'is_active' => true,
            ],
        );

        EmailTemplate::updateOrCreate(
            ['slug' => 'reporter-resolved'],
            [
                'name' => 'Reporter Resolution Notice',
                'type' => 'reporter_resolved',
                'subject' => 'Case Resolved — {{case_number}}',
                'body_html' => '<p>Dear {{reporter_name}},</p><p>Case <strong>{{case_number}}</strong> has been resolved.</p><p>{{resolution_summary}}</p><p>Thank you for helping us keep our network clean.</p><p>Regards,<br>Abuse AI Team</p>',
                'body_text' => "Dear {{reporter_name}},\n\nCase {{case_number}} has been resolved.\n\n{{resolution_summary}}\n\nThank you for helping us keep our network clean.\n\nRegards,\nAbuse AI Team",
                'variables' => ['reporter_name', 'case_number', 'resolution_summary'],
                'is_active' => true,
            ],
        );

        EmailTemplate::updateOrCreate(
            ['slug' => 'customer-warning'],
            [
                'name' => 'Customer Abuse Warning',
                'type' => 'customer_warning',
                'subject' => 'Abuse Notice — Action Required — {{case_number}}',
                'body_html' => '<p>Dear Customer,</p><p>We have received abuse reports regarding your service (IP: <strong>{{target_ip}}</strong>).</p><p><strong>Type:</strong> {{abuse_type}}<br><strong>Case:</strong> {{case_number}}</p><p>Please investigate and resolve the issue within 24 hours. Failure to act may result in service suspension.</p><p>If you believe this is an error, please reply to this email or submit an appeal through your customer portal.</p><p>Regards,<br>Abuse AI Team</p>',
                'body_text' => "Dear Customer,\n\nWe have received abuse reports regarding your service (IP: {{target_ip}}).\n\nType: {{abuse_type}}\nCase: {{case_number}}\n\nPlease investigate and resolve the issue within 24 hours. Failure to act may result in service suspension.\n\nIf you believe this is an error, please reply to this email or submit an appeal through your customer portal.\n\nRegards,\nAbuse AI Team",
                'variables' => ['case_number', 'abuse_type', 'target_ip', 'target_domain', 'severity_level'],
                'is_active' => true,
            ],
        );

        EmailTemplate::updateOrCreate(
            ['slug' => 'customer-suspension'],
            [
                'name' => 'Customer Suspension Notice',
                'type' => 'customer_suspension',
                'subject' => 'Service Suspended — {{case_number}}',
                'body_html' => '<p>Dear Customer,</p><p>Your service associated with IP <strong>{{target_ip}}</strong> has been suspended due to unresolved abuse violations.</p><p><strong>Case:</strong> {{case_number}}<br><strong>Type:</strong> {{abuse_type}}<br><strong>Severity:</strong> {{severity_level}}</p><p>To restore your service, please:</p><ol><li>Review the abuse details in your customer portal</li><li>Resolve the underlying issue</li><li>Submit an appeal with evidence of remediation</li></ol><p>Regards,<br>Abuse AI Team</p>',
                'body_text' => "Dear Customer,\n\nYour service associated with IP {{target_ip}} has been suspended due to unresolved abuse violations.\n\nCase: {{case_number}}\nType: {{abuse_type}}\nSeverity: {{severity_level}}\n\nTo restore your service, please:\n1. Review the abuse details in your customer portal\n2. Resolve the underlying issue\n3. Submit an appeal with evidence of remediation\n\nRegards,\nAbuse AI Team",
                'variables' => ['case_number', 'abuse_type', 'target_ip', 'severity_level'],
                'is_active' => true,
            ],
        );

        EmailTemplate::updateOrCreate(
            ['slug' => 'customer-appeal-result'],
            [
                'name' => 'Appeal Decision',
                'type' => 'customer_appeal_result',
                'subject' => 'Appeal Decision — {{case_number}}',
                'body_html' => '<p>Dear Customer,</p><p>We have reviewed your appeal for case <strong>{{case_number}}</strong>.</p><p><strong>Decision:</strong> {{verdict}}</p><p>{{verdict_details}}</p><p>Regards,<br>Abuse AI Team</p>',
                'body_text' => "Dear Customer,\n\nWe have reviewed your appeal for case {{case_number}}.\n\nDecision: {{verdict}}\n\n{{verdict_details}}\n\nRegards,\nAbuse AI Team",
                'variables' => ['case_number', 'verdict', 'verdict_details'],
                'is_active' => true,
            ],
        );
    }
}
