<?php
use ChurchCRM\dto\SystemURLs;

require SystemURLs::getDocumentRoot() . '/Include/Header.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

if (empty($_SESSION['philsms_csrf'])) {
    $_SESSION['philsms_csrf'] = bin2hex(random_bytes(24));
}

$base = htmlspecialchars($sRootPath, ENT_QUOTES, 'UTF-8');
$live = getenv('PHILSMS_LIVE_SEND') === '1';
?>

<div class="container py-3">
    <h2>PhilSMS Messaging</h2>

    <div class="alert alert-info">
        Mode:
        <strong>
            <?= $live ? 'LIVE — messages cost credits'
                      : 'DRY RUN — no messages sent' ?>
        </strong>
        <br>
        API token:
        <?= $configured ? 'configured' : 'missing' ?>
    </div>

    <div class="alert alert-light border mb-3">
        <strong>SMS Credits:</strong>

        <?php if (!empty($credits)): ?>
            <span class="text-success fw-bold">
                <?= htmlspecialchars(
                    $credits,
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </span>
        <?php else: ?>
            <span class="text-muted">
                <?= htmlspecialchars(
                    $creditsError ?? 'Unavailable',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </span>
        <?php endif; ?>
    </div>

    <form method="post"
          action="<?= $base ?>/plugins/philsms/send"
          id="philsms-form">

        <input type="hidden"
               name="csrf"
               value="<?= htmlspecialchars(
                   $_SESSION['philsms_csrf'],
                   ENT_QUOTES,
                   'UTF-8'
               ) ?>">

        <input type="hidden"
               name="person_ids"
               id="person_ids">

        <div class="card mb-3">
            <div class="card-header">
                <strong>Select ChurchCRM People</strong>
            </div>

            <div class="card-body">
                <label for="member-search" class="form-label">
                    Search by name or Person ID
                </label>

                <input type="search"
                       class="form-control mb-3"
                       id="member-search"
                       placeholder="Search ChurchCRM people...">

                <p>
                    <strong id="selected-count">0</strong>
                    of 25 recipients selected
                </p>

                <div style="max-height:350px;overflow-y:auto"
                     class="border rounded">

                    <?php foreach ($people as $person): ?>
                        <?php
                        $id = (int) $person['id'];
                        $name = htmlspecialchars(
                            $person['name'],
                            ENT_QUOTES,
                            'UTF-8'
                        );
                        $phone = (string) $person['phone'];
                        $masked = substr($phone, 0, 5)
                            . '****'
                            . substr($phone, -3);
                        ?>

                        <label class="d-block p-2 border-bottom member-row"
                               data-search="<?= htmlspecialchars(
                                   strtolower($person['name'] . ' ' . $id),
                                   ENT_QUOTES,
                                   'UTF-8'
                               ) ?>">

                            <input type="checkbox"
                                   class="member-checkbox me-2"
                                   value="<?= $id ?>">

                            <strong><?= $name ?></strong>

                            <small class="text-muted">
                                (#<?= $id ?>)
                                <?= htmlspecialchars(
                                    $masked,
                                    ENT_QUOTES,
                                    'UTF-8'
                                ) ?>
                            </small>
                        </label>
                    <?php endforeach; ?>

                </div>

                <?php if (empty($people)): ?>
                    <p class="text-muted mt-2">
                        No people with valid mobile numbers found.
                    </p>
                <?php endif; ?>

            </div>
        </div>

        <div class="mb-3">
            <label for="sms-message" class="form-label">
                SMS message
            </label>

            <textarea class="form-control"
                      name="message"
                      id="sms-message"
                      rows="5"
                      maxlength="1000"
                      required></textarea>

            <small class="text-muted">
                <span id="message-length">0</span>
                / 1000 characters
            </small>
        </div>

        <button class="btn btn-primary"
                type="submit"
                id="send-button"
                disabled>
            <?= $live ? 'Process SMS' : 'Run SMS Dry Test' ?>
        </button>
    </form>

<div id="philsms-result"
     class="alert mt-3"
     role="status"
     aria-live="polite"
     style="display:none;">
</div>

    <p class="mt-3 text-muted">
        Administrators only. Confirm recipient consent before
        sending. Maximum 25 recipients per submission.
    </p>
</div>

<script>
(function () {
    'use strict';

    const form = document.getElementById('philsms-form');
    const search = document.getElementById('member-search');
    const boxes = Array.from(
        document.querySelectorAll('.member-checkbox')
    );
    const rows = Array.from(
        document.querySelectorAll('.member-row')
    );
    const idsField = document.getElementById('person_ids');
    const counter = document.getElementById('selected-count');
    const message = document.getElementById('sms-message');
    const length = document.getElementById('message-length');
    const button = document.getElementById('send-button');

    function update() {
        const selected = boxes
            .filter(box => box.checked)
            .map(box => box.value);

        idsField.value = selected.join(',');
        counter.textContent = selected.length;
        length.textContent = Array.from(message.value).length;

        button.disabled =
            selected.length === 0 ||
            selected.length > 25 ||
            message.value.trim() === '';
    }

    function filterMembers() {
    const query = search.value.trim().toLowerCase();

    rows.forEach(function (row) {
        const searchableText = row.textContent.toLowerCase();

        row.style.setProperty(
            'display',
            searchableText.includes(query) ? 'block' : 'none',
            'important'
        );
    });
}

search.addEventListener('input', filterMembers);
search.addEventListener('search', filterMembers);
 
    boxes.forEach(box => {
        box.addEventListener('change', function () {
            if (boxes.filter(item => item.checked).length > 25) {
                box.checked = false;
                alert('Maximum of 25 recipients per submission.');
            }

            update();
        });
    });

    message.addEventListener('input', update);


form.addEventListener('submit', async function (event) {
    event.preventDefault();
    update();

    if (button.disabled) {
        return;
    }

    const count = Number(counter.textContent);

    if (!confirm(
        'Process SMS for ' + count + ' selected recipient(s)?'
    )) {
        return;
    }

    const resultBox = document.getElementById('philsms-result');
    const originalText = button.textContent;

    button.disabled = true;
    button.textContent = 'Processing...';

    resultBox.style.display = 'block';
    resultBox.className = 'alert alert-info mt-3';
    resultBox.textContent = 'Processing SMS request...';

    try {
        const response = await fetch(form.action, {
            method: 'POST',
            body: new FormData(form),
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json'
            }
        });

        const contentType = response.headers.get('content-type') || '';

        if (!contentType.includes('application/json')) {
            throw new Error('Unexpected server response.');
        }

        const data = await response.json();

        resultBox.className =
            response.ok && data.success
                ? 'alert alert-success mt-3'
                : 'alert alert-warning mt-3';

        const summary = document.createElement('div');
        summary.textContent = data.message || 'Request completed.';

        resultBox.replaceChildren(summary);

        if (Array.isArray(data.results)) {
            const list = document.createElement('ul');
            list.className = 'mb-0 mt-2';

            data.results.forEach(function (item) {
                const li = document.createElement('li');
                li.textContent = item;
                list.appendChild(li);
            });

            resultBox.appendChild(list);
        }

        if (data.dry_run === true) {
            const note = document.createElement('div');
            note.className = 'mt-2 fw-bold';
            note.textContent = 'DRY RUN — no SMS was sent.';
            resultBox.appendChild(note);
        }

    } catch (error) {
        resultBox.className = 'alert alert-danger mt-3';
        resultBox.textContent =
            'Unable to process the request. Check your connection and try again.';
    } finally {
        button.textContent = originalText;
        update();
    }
});


    update();
})();
</script>

<?php
require SystemURLs::getDocumentRoot() . '/Include/Footer.php';
?>
