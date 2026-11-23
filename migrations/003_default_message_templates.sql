INSERT IGNORE INTO philsms_message_templates
(template_key, template_name, message_body, is_enabled)
VALUES
(
 'guest_individual_welcome',
 'Individual Guest Welcome',
 'Hello {first_name}! Welcome to {church_name}. We are grateful that you joined us and look forward to seeing you again.',
 1
),
(
 'guest_family_welcome',
 'Family Guest Welcome',
 'Welcome to {church_name}, {family_name} family! We are grateful that your family joined us. We look forward to worshiping with you again.',
 1
),
(
 'guest_general_welcome',
 'General Guest Welcome',
 'Thank you for visiting {church_name}! We are grateful you joined us and hope to see you again soon.',
 1
),
(
 'guest_followup',
 'Guest Follow-up',
 'Hello {first_name}! Thank you for visiting {church_name}. We hope you are doing well and look forward to seeing you again.',
 1
),
(
 'member_followup',
 'Assigned Leader Follow-up',
 'Hello {leader_name}. Please follow up with our new guest, {guest_name}, within the next seven days. Thank you for serving at {church_name}.',
 1
);
