<?php
// Backward-compatibility endpoint. The user-facing User-Agent picker now
// lives in the Headers tab on the entry form and POSTs to index.php?action=set-ua.
// Old bookmarks pointing at edit.php still save the User-Agent and then
// bounce to the entry form.

if (isset($_POST['action']) && $_POST['action'] === 'submit' && isset($_POST['userAgent']))
{
    $userAgent = (string) $_POST['userAgent'];
    if (strpbrk($userAgent, "\r\n") === false) {
        setcookie('userAgent', $userAgent, time() + 86400 * 365, '/');
    }
}

header('Location: index.php');
exit;
