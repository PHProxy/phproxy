<?php
// Shared tabs panel — Options, Cookies, Headers, (optional) Response Headers.
// Rendered both inside the entry form (files/php/index.inc.php) and inside
// the inline panel injected onto proxied pages (index.php response-body
// rewriting block).
//
// Inputs (set via $GLOBALS or as locals before include):
//   $_panel_return_to        URL string. When set, every form in the panel
//                            carries a hidden return_to input so the
//                            dispatcher redirects there instead of to the
//                            entry form. Empty = use the default per-action
//                            redirect.
//   $_panel_show_response    bool. true = render the Response Headers tab.
//   $_panel_response_pairs   array of [name, value] pairs to show in that tab.
//   $_panel_active_tab       'options' | 'cookies' | 'headers' | 'response'
//   $_panel_form_id          string. If non-empty, options-tab checkboxes use
//                            form="<this>" so they ride along on URL submit.
//                            Empty = no form attribute (used in the inline
//                            panel where there is no main URL form to ride).
//
// Reads existing state from $GLOBALS: _config, _flags, _frozen_flags,
// _labels, _visible_cookies, _custom_headers, _current_ua, _ua_presets,
// _show_raw_values.

if (!isset($_panel_return_to))      $_panel_return_to      = '';
if (!isset($_panel_show_response))  $_panel_show_response  = false;
if (!isset($_panel_response_pairs)) $_panel_response_pairs = [];
if (!isset($_panel_request_pairs))  $_panel_request_pairs  = [];
if (!isset($_panel_request_line))   $_panel_request_line   = '';
if (!isset($_panel_active_tab))     $_panel_active_tab     = 'options';
if (!isset($_panel_form_id))        $_panel_form_id        = '';

$_panel_return_html = $_panel_return_to !== ''
    ? '<input type="hidden" name="return_to" value="' . htmlspecialchars($_panel_return_to, ENT_QUOTES) . '"/>'
    : '';
$_form_attr = $_panel_form_id !== '' ? ' form="' . htmlspecialchars($_panel_form_id) . '"' : '';

$_cfg          = $GLOBALS['_config'];
$_flags_local  = $GLOBALS['_flags'];
$_frozen       = $GLOBALS['_frozen_flags'];
$_labels_local = $GLOBALS['_labels'];
$_v_cookies    = $GLOBALS['_visible_cookies'] ?? [];
$_c_headers    = $GLOBALS['_custom_headers']  ?? [];
$_show_raw     = !empty($GLOBALS['_show_raw_values']);
$_current_ua_local = isset($_COOKIE['userAgent']) ? $_COOKIE['userAgent'] : '';
$_ua_presets_local = $GLOBALS['_ua_presets'] ?? [];
?>
<div class="tabs-wrap">
    <input type="radio" name="tab" id="tab-options"<?php echo $_panel_active_tab === 'options' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-cookies"<?php echo $_panel_active_tab === 'cookies' ? ' checked' : ''; ?>/>
    <input type="radio" name="tab" id="tab-headers"<?php echo $_panel_active_tab === 'headers' ? ' checked' : ''; ?>/>
    <?php if ($_panel_show_response): ?>
    <input type="radio" name="tab" id="tab-response"<?php echo $_panel_active_tab === 'response' ? ' checked' : ''; ?>/>
    <?php endif; ?>

    <nav class="tabs">
        <label for="tab-options">Options</label>
        <label for="tab-cookies">Cookies <span class="tab-count"><?php echo count($_v_cookies); ?></span></label>
        <label for="tab-headers">Headers <span class="tab-count"><?php echo count($_c_headers); ?></span></label>
        <?php if ($_panel_show_response): ?>
        <label for="tab-response">Trace <span class="tab-count"><?php echo count($_panel_request_pairs) + count($_panel_response_pairs); ?></span></label>
        <?php endif; ?>
    </nav>

    <section class="tab-panel" data-tab="options">
<?php
$_option_groups = [
    'Browsing' => ['include_form', 'remove_scripts', 'show_images', 'strip_iframes', 'block_3p', 'block_fonts', 'block_media'],
    'Privacy'  => ['show_referer', 'send_dnt', 'send_gpc', 'strip_title', 'strip_meta', 'strip_tracking'],
    'Cookies'  => ['accept_cookies', 'session_cookies'],
];
foreach ($_option_groups as $_group_name => $_group_flags):
    $_visible = false;
    foreach ($_group_flags as $_f) {
        if (!$_frozen[$_f]) { $_visible = true; break; }
    }
    if (!$_visible) continue;
?>
        <fieldset class="option-group">
            <legend><?php echo htmlspecialchars($_group_name); ?></legend>
            <ul class="prx-opt-menu">
<?php foreach ($_group_flags as $_f): ?>
<?php if (!$_frozen[$_f]): ?>
                <li class="option"><label><input<?php echo $_form_attr; ?> type="checkbox" name="<?php echo $_cfg['flags_var_name']; ?>[<?php echo $_f; ?>]"<?php echo $_flags_local[$_f] ? ' checked' : ''; ?>/><span><?php echo $_labels_local[$_f][0]; ?></span></label></li>
<?php endif; ?>
<?php endforeach; ?>
            </ul>
        </fieldset>
<?php endforeach; ?>
<?php
$_url_encoding = 'none';
if (!empty($_flags_local['encrypt_url']))   $_url_encoding = 'encrypted';
elseif ($_flags_local['rotate13'])          $_url_encoding = 'rot13';
elseif ($_flags_local['base64_encode'])     $_url_encoding = 'base64';
$_seed_ttl  = phproxy_seed_ttl();
$_seed_bits = phproxy_seed_bits();
$_seed_present = !empty($_COOKIE['phproxy-seed']);
?>
        <fieldset class="option-group">
            <legend>Address bar</legend>
            <div class="radio-row">
                <span class="radio-row-label">URL encoding</span>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="none"<?php echo $_url_encoding === 'none' ? ' checked' : ''; ?>/> None</label>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="rot13"<?php echo $_url_encoding === 'rot13' ? ' checked' : ''; ?>/> ROT13</label>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="base64"<?php echo $_url_encoding === 'base64' ? ' checked' : ''; ?>/> Base64</label>
                <label><input<?php echo $_form_attr; ?> type="radio" name="<?php echo $_cfg['flags_var_name']; ?>[__url_enc]" value="encrypted"<?php echo $_url_encoding === 'encrypted' ? ' checked' : ''; ?>/> Encrypted <span class="hint">(rotating key)</span></label>
            </div>
            <div class="seed-config">
                <p class="tab-help">Encrypted mode wraps each URL with a fresh AES-CTR ciphertext keyed off a random session seed. Once the seed rotates, any URL recorded earlier (logs, history, referers) becomes useless.</p>
                <div class="seed-row">
                    <form class="seed-mini" method="post" action="?action=set-seed-ttl">
                        <?php echo $_panel_return_html; ?>
                        <label class="seed-mini-label">Seed lifetime <span class="hint">(seconds)</span></label>
                        <input type="number" name="seedTtl" min="60" max="604800" step="60" value="<?php echo (int) $_seed_ttl; ?>"/>
                        <button class="button-cancel" type="submit">Save</button>
                    </form>
                    <form class="seed-mini" method="post" action="?action=set-seed-bits">
                        <?php echo $_panel_return_html; ?>
                        <label class="seed-mini-label">Key length</label>
                        <select name="seedBits">
                            <option value="128"<?php echo $_seed_bits === 128 ? ' selected' : ''; ?>>AES-128</option>
                            <option value="192"<?php echo $_seed_bits === 192 ? ' selected' : ''; ?>>AES-192</option>
                            <option value="256"<?php echo $_seed_bits === 256 ? ' selected' : ''; ?>>AES-256</option>
                        </select>
                        <button class="button-cancel" type="submit">Save</button>
                    </form>
                    <form class="seed-mini" method="post" action="?action=rotate-seed">
                        <?php echo $_panel_return_html; ?>
                        <label class="seed-mini-label">Seed <span class="hint"><?php echo $_seed_present ? 'live' : 'not set'; ?></span></label>
                        <button class="button-ghost" type="submit" title="Generate a new random seed now">Rotate now</button>
                    </form>
                </div>
            </div>
        </fieldset>
    </section>

    <section class="tab-panel" data-tab="cookies">
        <div class="tab-toolbar">
            <p class="tab-help">Cookies held for proxied sites. Click a row to edit any attribute.</p>
            <form class="toolbar-toggle" method="post" action="?action=toggle-raw">
                <?php echo $_panel_return_html; ?>
                <button type="submit" class="button-cancel" title="Switch between URL-decoded and raw wire-form values"><?php echo $_show_raw ? 'Showing: raw' : 'Showing: decoded'; ?></button>
            </form>
        </div>
<?php if (empty($_v_cookies)): ?>
        <ul class="kv-list"><li class="empty">No cookies yet</li></ul>
<?php else: ?>
        <ul class="kv-list">
<?php
foreach ($_v_cookies as $_wire => $_c):
    $_disp_value = $_show_raw ? $_c['raw_value'] : $_c['value'];
    $_disp_name  = $_show_raw ? $_c['raw_name']  : $_c['display_name'];
?>
            <li class="kv-row">
                <details class="kv-card">
                    <summary>
                        <span class="kv-display">
                            <?php if ($_c['is_proxy']): ?><span class="kv-host"><?php echo htmlspecialchars($_c['host']); ?></span><span class="kv-sep">·</span><?php endif; ?>
                            <span class="kv-name"><?php echo htmlspecialchars($_disp_name); ?></span><span class="kv-sep">=</span><span class="kv-val"><?php echo htmlspecialchars(mb_strimwidth($_disp_value, 0, 72, '…')); ?></span>
                        </span>
                        <span class="kv-chevron" aria-hidden="true">▾</span>
                    </summary>
                    <form class="kv-edit kv-edit-cookie" method="post" action="?action=edit-cookie">
                        <?php echo $_panel_return_html; ?>
                        <input type="hidden" name="name" value="<?php echo htmlspecialchars($_wire); ?>"/>
                        <label class="kv-edit-field"><span>Name</span><input type="text" name="cookieName" value="<?php echo htmlspecialchars($_c['display_name']); ?>" autocomplete="off"/></label>
                        <label class="kv-edit-field kv-edit-wide"><span>Value</span><input type="text" name="cookieValue" value="<?php echo htmlspecialchars($_c['value']); ?>" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Host</span><input type="text" name="cookieDomain" value="<?php echo $_c['is_proxy'] ? htmlspecialchars($_c['domain']) : ''; ?>" placeholder=".example.com" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Path</span><input type="text" name="cookiePath" value="<?php echo htmlspecialchars($_c['path'] ?: '/'); ?>" placeholder="/" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Expires <span class="hint">(days, 0 = session)</span></span><input type="number" name="cookieExpires" value="30" min="0" max="3650"/></label>
                        <label class="kv-edit-field"><span>SameSite</span>
                            <select name="cookieSameSite">
                                <option value="">(default)</option>
                                <option value="Lax">Lax</option>
                                <option value="Strict">Strict</option>
                                <option value="None">None</option>
                            </select>
                        </label>
                        <div class="kv-edit-checks">
                            <label class="kv-edit-check"><input type="checkbox" name="cookieSecure"<?php echo $_c['secure'] ? ' checked' : ''; ?>/> Secure</label>
                            <label class="kv-edit-check"><input type="checkbox" name="cookieHttpOnly"/> HttpOnly</label>
                        </div>
                        <div class="kv-edit-actions">
                            <button class="button-submit" type="submit">Save</button>
                        </div>
                    </form>
                </details>
                <form class="kv-delete" method="post" action="?action=delete-cookie">
                    <?php echo $_panel_return_html; ?>
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($_wire); ?>"/>
                    <button class="button-icon" type="submit" title="Delete cookie" aria-label="Delete <?php echo htmlspecialchars($_c['display_name']); ?>">&times;</button>
                </form>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <details class="kv-card kv-card-add">
            <summary><span class="kv-display"><span class="kv-name">+ Add a cookie</span></span><span class="kv-chevron" aria-hidden="true">▾</span></summary>
            <form class="kv-edit kv-edit-cookie" method="post" action="?action=add-cookie">
                <?php echo $_panel_return_html; ?>
                <label class="kv-edit-field"><span>Name</span><input type="text" name="cookieName" placeholder="session_id" autocomplete="off" required/></label>
                <label class="kv-edit-field kv-edit-wide"><span>Value</span><input type="text" name="cookieValue" placeholder="abc123" autocomplete="off"/></label>
                <label class="kv-edit-field"><span>Host <span class="hint">(empty = proxy domain)</span></span><input type="text" name="cookieDomain" placeholder=".example.com" autocomplete="off"/></label>
                <label class="kv-edit-field"><span>Path</span><input type="text" name="cookiePath" value="/" placeholder="/" autocomplete="off"/></label>
                <label class="kv-edit-field"><span>Expires <span class="hint">(days, 0 = session)</span></span><input type="number" name="cookieExpires" value="30" min="0" max="3650"/></label>
                <label class="kv-edit-field"><span>SameSite</span>
                    <select name="cookieSameSite">
                        <option value="">(default)</option>
                        <option value="Lax" selected>Lax</option>
                        <option value="Strict">Strict</option>
                        <option value="None">None</option>
                    </select>
                </label>
                <div class="kv-edit-checks">
                    <label class="kv-edit-check"><input type="checkbox" name="cookieSecure"/> Secure</label>
                    <label class="kv-edit-check"><input type="checkbox" name="cookieHttpOnly"/> HttpOnly</label>
                </div>
                <div class="kv-edit-actions">
                    <button class="button-submit" type="submit">Add cookie</button>
                </div>
            </form>
        </details>
        <form class="tab-actions" method="post" action="?action=clear-cookies">
            <?php echo $_panel_return_html; ?>
            <button class="button-ghost" type="submit">Clear all cookies</button>
        </form>
    </section>

    <section class="tab-panel" data-tab="headers">
        <p class="section-label">User-Agent</p>
        <p class="tab-help">Sent on every proxied request. <code>.</code> means use my real browser's User-Agent, <code>-</code> means send none.</p>
        <form class="ua-form" method="post" action="?action=set-ua">
            <?php echo $_panel_return_html; ?>
            <div class="ua-picker">
                <select id="ua-preset" aria-label="User-Agent preset">
<?php foreach ($_ua_presets_local as $_val => $_lbl): ?>
                    <option value="<?php echo htmlspecialchars($_val); ?>"<?php echo $_current_ua_local === $_val ? ' selected' : ''; ?>><?php echo htmlspecialchars($_lbl); ?></option>
<?php endforeach; ?>
                    <option value="__custom__"<?php echo (!array_key_exists($_current_ua_local, $_ua_presets_local) && $_current_ua_local !== '') ? ' selected' : ''; ?>>Custom…</option>
                </select>
                <input type="text" id="ua-input" name="userAgent" value="<?php echo htmlspecialchars($_current_ua_local); ?>" placeholder="Custom User-Agent string" autocomplete="off"/>
            </div>
            <div class="tab-actions" style="margin-top:12px">
                <button class="button-submit" type="submit">Save User-Agent</button>
            </div>
        </form>

        <hr class="divider"/>

        <p class="section-label">Custom headers</p>
        <p class="tab-help">Extra HTTP headers sent on every outbound request. Header names: ASCII letters, digits, hyphens.</p>
<?php if (empty($_c_headers)): ?>
        <ul class="kv-list"><li class="empty">No custom headers</li></ul>
<?php else: ?>
        <ul class="kv-list">
<?php foreach ($_c_headers as $_h_name => $_h_value): ?>
            <li class="kv-row">
                <details class="kv-card">
                    <summary>
                        <span class="kv-display">
                            <span class="kv-name"><?php echo htmlspecialchars($_h_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars(mb_strimwidth($_h_value, 0, 72, '…')); ?></span>
                        </span>
                        <span class="kv-chevron" aria-hidden="true">▾</span>
                    </summary>
                    <form class="kv-edit" method="post" action="?action=edit-header">
                        <?php echo $_panel_return_html; ?>
                        <input type="hidden" name="oldName" value="<?php echo htmlspecialchars($_h_name); ?>"/>
                        <label class="kv-edit-field"><span>Name</span><input type="text" name="headerName" value="<?php echo htmlspecialchars($_h_name); ?>" pattern="[A-Za-z0-9-]+" autocomplete="off"/></label>
                        <label class="kv-edit-field"><span>Value</span><input type="text" name="headerValue" value="<?php echo htmlspecialchars($_h_value); ?>" autocomplete="off"/></label>
                        <div class="kv-edit-actions">
                            <button class="button-submit" type="submit">Save</button>
                        </div>
                    </form>
                </details>
                <form class="kv-delete" method="post" action="?action=delete-header">
                    <?php echo $_panel_return_html; ?>
                    <input type="hidden" name="name" value="<?php echo htmlspecialchars($_h_name); ?>"/>
                    <button class="button-icon" type="submit" title="Delete header" aria-label="Delete header <?php echo htmlspecialchars($_h_name); ?>">&times;</button>
                </form>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
        <p class="section-label">Add a header</p>
        <form class="kv-add" method="post" action="?action=add-header">
            <?php echo $_panel_return_html; ?>
            <input type="text" name="headerAddName" placeholder="Accept-Language" pattern="[A-Za-z0-9-]+" autocomplete="off"/>
            <input type="text" name="headerAddValue" placeholder="en-GB,en;q=0.9" autocomplete="off"/>
            <button type="submit">Add</button>
        </form>
    </section>

    <?php if ($_panel_show_response): ?>
    <section class="tab-panel" data-tab="response">
        <p class="section-label">Request to upstream</p>
        <p class="tab-help">The HTTP request the proxy sent on your behalf for this URL.</p>
<?php if ($_panel_request_line !== ''): ?>
        <ul class="kv-list">
            <li class="kv-row kv-row-readonly">
                <div class="kv-card">
                    <div class="kv-card-readonly kv-card-status"><?php echo htmlspecialchars($_panel_request_line); ?></div>
                </div>
            </li>
<?php foreach ($_panel_request_pairs as $_rh): list($_rh_name, $_rh_value) = $_rh; ?>
            <li class="kv-row kv-row-readonly">
                <div class="kv-card">
                    <div class="kv-card-readonly">
                        <span class="kv-name"><?php echo htmlspecialchars($_rh_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars($_rh_value); ?></span>
                    </div>
                </div>
            </li>
<?php endforeach; ?>
        </ul>
<?php else: ?>
        <ul class="kv-list"><li class="empty">No request captured</li></ul>
<?php endif; ?>

        <hr class="divider"/>
        <p class="section-label">Response from upstream</p>
        <p class="tab-help">Headers the upstream server sent back.</p>
<?php if (empty($_panel_response_pairs)): ?>
        <ul class="kv-list"><li class="empty">No response headers captured</li></ul>
<?php else: ?>
        <ul class="kv-list">
<?php foreach ($_panel_response_pairs as $_rh): list($_rh_name, $_rh_value) = $_rh; ?>
            <li class="kv-row kv-row-readonly">
                <div class="kv-card">
                    <div class="kv-card-readonly<?php echo $_rh_value === '' ? ' kv-card-status' : ''; ?>">
<?php if ($_rh_value === ''): ?>
                        <?php echo htmlspecialchars($_rh_name); ?>
<?php else: ?>
                        <span class="kv-name"><?php echo htmlspecialchars($_rh_name); ?></span><span class="kv-sep">:</span><span class="kv-val"><?php echo htmlspecialchars($_rh_value); ?></span>
<?php endif; ?>
                    </div>
                </div>
            </li>
<?php endforeach; ?>
        </ul>
<?php endif; ?>
    </section>
    <?php endif; ?>
</div>
