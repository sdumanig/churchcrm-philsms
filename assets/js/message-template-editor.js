/*
 * PhilSMS Message Template Editor.
 * Preview-only initialization; saving is implemented separately.
 */
(function () {
    'use strict';

    const templates = document.getElementById('philsms-template-data');
    const modal = document.getElementById('philsms-template-editor-modal');
    const buttons = document.querySelectorAll('.philsms-edit-template');

    if (!templates || !modal ||
        !window.bootstrap?.Modal ||
        !window.PhilSMSSegments?.calculate) {
        console.error('[PhilSMS] Template editor dependencies unavailable.');
        return;
    }

    let templateData;

    try {
        templateData = JSON.parse(templates.textContent);
    } catch (error) {
        console.error('[PhilSMS] Invalid template data.');
        return;
    }

    if (!Array.isArray(templateData)) {
        return;
    }

    const keyField = document.getElementById('philsms-template-key');
    const nameField = document.getElementById('philsms-template-name');
    const bodyField = document.getElementById('philsms-template-body');
    const enabledField = document.getElementById('philsms-template-enabled');
    const preview = document.getElementById('philsms-template-preview');
    const usage = document.getElementById('philsms-template-sms-usage');

    const samples = {
        first_name: 'Juan',
        last_name: 'Dela Cruz',
        full_name: 'Juan Dela Cruz',
        family_name: 'Dela Cruz',
        guest_name: 'Juan Dela Cruz',
        church_name: 'Soli Deo Gloria Christian Church',
        leader_name: 'Pedro Santos',
        service_schedule: 'Sunday at 9:00 AM'
    };

    const saveButton = document.getElementById('philsms-template-save');
    const result = document.getElementById('philsms-template-result');

    const allowedPlaceholders = new Set(Object.keys(samples));

    function validateTemplate() {
        const name = nameField.value.trim();
        const body = bodyField.value.trim();

        let error = '';

        if (!name || Array.from(name).length > 120) {
            error = 'Template name must contain 1–120 characters.';
        } else if (!body || Array.from(body).length > 1000) {
            error = 'Template message must contain 1–1000 characters.';
        } else {
            const placeholders = body.match(/\{[^{}]*\}/g) || [];

            for (const placeholder of placeholders) {
                const key = placeholder.slice(1, -1);

                if (!allowedPlaceholders.has(key)) {
                    error = 'Unsupported placeholder: ' + placeholder;
                    break;
                }
            }

            if (!error) {
                const remaining = body.replace(/\{[^{}]*\}/g, '');

                if (remaining.includes('{') || remaining.includes('}')) {
                    error = 'Malformed template placeholder.';
                }
            }
        }

        saveButton.disabled = saving || Boolean(error);

        result.textContent = error;
        result.className = error
            ? 'small text-danger'
            : 'small text-muted';

        return !error;
    }

    function renderPreview() {
        const message = bodyField.value.replace(
            /\{([a-z_]+)\}/g,
            function (match, key) {
                return Object.prototype.hasOwnProperty.call(samples, key)
                    ? samples[key]
                    : match;
            }
        );

        preview.textContent = message || 'Enter a message to preview.';

        const sms = window.PhilSMSSegments.calculate(message);

        usage.textContent =
            sms.characters + ' characters · ' +
            sms.encoding + ' · ' +
            sms.segments + ' SMS segment(s) · ' +
            sms.units + ' encoding units';
    }

    bodyField.addEventListener('input', function () {
        renderPreview();
        validateTemplate();
    });

    nameField.addEventListener('input', validateTemplate);

    const editorForm = document.getElementById(
        'philsms-template-editor-form'
    );
    const csrfField = document.getElementById(
        'philsms-template-csrf'
    );

    let saving = false;

    async function saveTemplate() {
        if (saving || !validateTemplate()) {
            return;
        }

        if (!editorForm.dataset.saveUrl || !csrfField.value) {
            result.textContent = 'Template save configuration is missing.';
            result.className = 'small text-danger';
            return;
        }

        const payload = new URLSearchParams({
            csrf: csrfField.value,
            template_key: keyField.value,
            template_name: nameField.value,
            message_body: bodyField.value,
            is_enabled: enabledField.checked ? '1' : '0'
        });

        saving = true;
        saveButton.disabled = true;
        saveButton.textContent = 'Saving...';

        result.textContent = 'Saving message template...';
        result.className = 'small text-muted';

        try {
            const response = await fetch(editorForm.dataset.saveUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json',
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8'
                },
                body: payload.toString()
            });

            const contentType =
                response.headers.get('content-type') || '';

            if (!contentType.includes('application/json')) {
                throw new Error('Unexpected server response.');
            }

            const data = await response.json();

            if (!response.ok || data.success !== true) {
                result.textContent =
                    data.message || 'Unable to save the template.';
                result.className = 'small text-danger';
                return;
            }

            result.textContent =
                data.message || 'Message template saved.';
            result.className = 'small text-success';

            window.location.reload();

        } catch (error) {
            result.textContent =
                'Unable to save the template. Check your connection and try again.';
            result.className = 'small text-danger';

        } finally {
            saving = false;
            saveButton.textContent = 'Save Template';

            const feedbackText = result.textContent;
            const feedbackClass = result.className;

            validateTemplate();

            result.textContent = feedbackText;
            result.className = feedbackClass;
        }
    }

    saveButton.addEventListener('click', saveTemplate);

    editorForm.addEventListener('submit', function (event) {
        event.preventDefault();
        saveTemplate();
    });

    buttons.forEach(function (button) {
        const key = button.dataset.templateKey;
        const template = templateData.find(
            item => item.template_key === key
        );

        if (!template) {
            return;
        }

        button.disabled = false;

        button.addEventListener('click', function () {
            keyField.value = template.template_key;
            nameField.value = template.template_name;
            bodyField.value = template.message_body;
            enabledField.checked = Number(template.is_enabled) === 1;

            document.getElementById('philsms-template-result')
                .textContent = '';

            renderPreview();
            validateTemplate();

            window.bootstrap.Modal.getOrCreateInstance(modal).show();
        });
    });
})();
