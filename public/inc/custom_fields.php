<?php
declare(strict_types=1);
// Custom fields (4.24.0): an intake form builder. Administrators add fields of their own (Administration,
// Custom fields) to client records: short or long text, a number, a date, a choice from a list, or yes or
// no, each optionally required, and optionally asked on the public referral form (with a Spanish label),
// where the answer is kept with the referral and carried to the client when it is converted. Values live
// in custom_values; a field is turned off rather than deleted, so answers given are kept.

const CUSTOM_FIELD_TYPES = ['text' => 'Short text', 'textarea' => 'Longer text', 'number' => 'Number', 'date' => 'Date', 'choice' => 'Choose one from a list', 'yesno' => 'Yes or no'];

/** The fields, in order (active only when $activeOnly); empty before mig_4.24.0. */
function custom_fields_all(bool $activeOnly = false, bool $fresh = false): array {
    static $cache = null;
    if ($cache === null || $fresh) {
        try { $cache = db()->query('SELECT * FROM custom_fields ORDER BY sort_order, id')->fetchAll(); }
        catch (PDOException $e) { $cache = []; }
    }
    return $activeOnly ? array_values(array_filter($cache, fn($f) => (int)$f['is_active'] === 1)) : $cache;
}

/** A choice field's options, one per line. */
function custom_field_options(array $f): array {
    return array_values(array_unique(array_filter(array_map('trim', explode("\n", str_replace("\r", '', (string)$f['options']))), fn($o) => $o !== '')));
}

/** The label to show: Spanish on a Spanish page when there is one. */
function custom_field_label(array $f, bool $public = false): string {
    return $public && function_exists('page_lang') && page_lang() === 'es' && trim((string)$f['label_es']) !== '' ? (string)$f['label_es'] : (string)$f['label'];
}

/** Stored values for a client or a referral: field id => value. */
function custom_values_for(?int $clientId, ?int $referralId = null): array {
    try {
        $q = db()->prepare('SELECT field_id, value FROM custom_values WHERE ' . ($clientId !== null ? 'client_id = ?' : 'referral_id = ?'));
        $q->execute([$clientId ?? $referralId]);
        $out = [];
        foreach ($q->fetchAll() as $r) $out[(int)$r['field_id']] = (string)$r['value'];
        return $out;
    } catch (PDOException $e) {
        return [];
    }
}

/** Whether a stored answer $v is valid for field $f's type (4.27.0: checked before a type change). */
function custom_value_fits(array $f, string $v): bool {
    if ($v === '') return true;
    return match ((string)$f['type']) {
        'text' => mb_strlen($v) <= 255,
        'textarea' => mb_strlen($v) <= 4000,
        'number' => is_numeric($v) && abs((float)$v) <= 1e12,
        'date' => valid_date($v, 1900, (int)date('Y') + 10) === $v,
        'choice' => in_array($v, custom_field_options($f), true),
        'yesno' => in_array($v, ['Yes', 'No'], true),
        default => true,
    };
}

/** How many answers already given to field $fieldId (clients and referrals) would not fit $f's type and choices. */
function custom_field_misfits(array $f, int $fieldId): int {
    try {
        $q = db()->prepare('SELECT value FROM custom_values WHERE field_id = ?');
        $q->execute([$fieldId]);
    } catch (PDOException $e) {
        return 0;
    }
    $n = 0;
    foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $v) if (!custom_value_fits($f, (string)$v)) $n++;
    return $n;
}

/**
 * Check posted values (cf_<id>) for $fields. Only fields that were sent are read, so a form or app that
 * does not show them changes nothing. A choice already stored stays valid after its option is renamed or removed
 * ($stored: field id => value). Returns [values (id => string, '' to clear), errors (cf_<id> => text)].
 */
function custom_input(array $post, array $fields, bool $public = false, array $stored = []): array {
    $vals = []; $errs = [];
    // Messages are English source copy, as every other message (4.27.0). On a Spanish public page the Spanish
    // wording is registered as its translation, so t() in field_error() and the summary shows it marked
    // lang="es" rather than wrapping ready-made Spanish as English.
    $es = $public && function_exists('page_lang') && function_exists('register_translations') && page_lang() === 'es';
    $label = ''; $labelEs = '';
    $m = function (string $en, string $esText) use ($es, &$label, &$labelEs): string {
        if ($es) register_translations([$label . $en => $labelEs . $esText]);
        return $label . $en;
    };
    foreach ($fields as $f) {
        $k = 'cf_' . (int)$f['id'];
        if (!array_key_exists($k, $post) || !is_string($post[$k])) continue; // not on this form (or not a single value): left as it is
        $v = trim(str_replace("\r", '', $post[$k]));
        $label = (string)$f['label'];
        $labelEs = custom_field_label($f, $public);
        // An answer sent back unchanged is kept as it is (4.27.0), even when it no longer fits the field's
        // type or list (a type changed before this release): staff are not blocked from saving the record.
        if ($v !== '' && array_key_exists((int)$f['id'], $stored) && $v === (string)$stored[(int)$f['id']]) { $vals[(int)$f['id']] = $v; continue; }
        if ($v === '') {
            if ((int)$f['required'] === 1) $errs[$k] = $m(': this is required.', ': esta respuesta es obligatoria.');
            $vals[(int)$f['id']] = '';
            continue;
        }
        switch ($f['type']) {
            case 'text': if (mb_strlen($v) > 255) $errs[$k] = $m(': keep it to 255 characters.', ': use 255 caracteres como máximo.'); break;
            case 'textarea': if (mb_strlen($v) > 4000) $errs[$k] = $m(': keep it to 4,000 characters.', ': use 4,000 caracteres como máximo.'); break;
            case 'number': $n = str_replace(',', '', $v); if (!is_numeric($n) || abs((float)$n) > 1e12) $errs[$k] = $m(': enter a number.', ': escriba un número.'); else $v = $n; break;
            case 'date': $d = valid_date($v, 1900, (int)date('Y') + 10); if ($d === null) $errs[$k] = $m(': enter a full date.', ': escriba una fecha completa.'); else $v = $d; break;
            case 'choice': if (!in_array($v, custom_field_options($f), true) && $v !== ($stored[(int)$f['id']] ?? null)) $errs[$k] = $m(': choose one from the list.', ': elija una opción de la lista.'); break;
            case 'yesno': if (!in_array($v, ['Yes', 'No'], true)) $errs[$k] = $m(': choose Yes or No.', ': elija Sí o No.'); break;
        }
        $vals[(int)$f['id']] = $v;
    }
    return [$vals, $errs];
}

/** Save values for a client or a referral ('' removes one). */
function custom_save(array $vals, ?int $clientId, ?int $referralId = null): void {
    foreach ($vals as $fid => $v) {
        $col = $clientId !== null ? 'client_id' : 'referral_id';
        $owner = $clientId ?? $referralId;
        if ($v === '') { db()->prepare("DELETE FROM custom_values WHERE field_id = ? AND $col = ?")->execute([(int)$fid, $owner]); continue; }
        db()->prepare("INSERT INTO custom_values (field_id, $col, value) VALUES (?,?,?) ON DUPLICATE KEY UPDATE value = VALUES(value)")->execute([(int)$fid, $owner, $v]);
    }
}

/** A referral's answers become the client's when it is converted (the client's own are not replaced). */
function custom_copy_referral_to_client(int $referralId, int $clientId): void {
    try {
        db()->prepare('INSERT IGNORE INTO custom_values (field_id, client_id, value) SELECT field_id, ?, value FROM custom_values WHERE referral_id = ?')->execute([$clientId, $referralId]);
    } catch (PDOException $e) {}
}

/** The form controls for $fields with $values (posted or stored), labelled for a public page when $public. */
function custom_fields_html(array $fields, array $values, bool $public = false): string {
    $tr = fn(string $s): string => $public && function_exists('t') ? t($s) : h($s);
    $out = '';
    foreach ($fields as $f) {
        $id = 'cf_' . (int)$f['id'];
        $val = (string)($values[(int)$f['id']] ?? '');
        $label = h(custom_field_label($f, $public)) . ((int)$f['required'] === 1 ? ' ' . ($public ? '<span aria-hidden="true">*</span><span class="visually-hidden"> (' . $tr('required') . ')</span>' : required_mark()) : '');
        $hint = trim((string)$f['hint']) !== '' ? ' <span class="hint">' . h((string)$f['hint']) . '</span>' : '';
        $aria = field_aria($id);
        $out .= '<div class="field"><label for="' . $id . '">' . $label . $hint . '</label>';
        switch ($f['type']) {
            case 'textarea': $out .= '<textarea id="' . $id . '" name="' . $id . '" rows="3" ' . $aria . '>' . h($val) . '</textarea>'; break;
            case 'number': $out .= '<input type="text" inputmode="decimal" id="' . $id . '" name="' . $id . '" maxlength="20" value="' . h($val) . '" ' . $aria . '>'; break;
            case 'date':
                // A saved answer that is not a date (4.27.0) is shown in a text box, said so in the label, and kept
                // when sent back unchanged: a date box would show it blank and erase it on the next save.
                if ($val !== '' && !custom_value_fits($f, $val)) {
                    $out = substr($out, 0, -strlen('</label>')) . ' <span class="hint">' . $tr('(the saved answer is not a date; type a date written 2026-10-01 to replace it)') . '</span></label>';
                    $out .= '<input type="text" id="' . $id . '" name="' . $id . '" maxlength="255" value="' . h($val) . '" ' . $aria . '>';
                } else $out .= '<input type="date" id="' . $id . '" name="' . $id . '" value="' . h($val) . '" ' . $aria . '>';
                break;
            case 'choice': case 'yesno':
                $opts = $f['type'] === 'yesno' ? ['Yes', 'No'] : custom_field_options($f);
                $out .= '<select id="' . $id . '" name="' . $id . '" ' . $aria . '><option value="">' . $tr('Not answered') . '</option>';
                foreach ($opts as $o) $out .= '<option value="' . h($o) . '"' . ($o === $val ? ' selected' : '') . '>' . ($f['type'] === 'yesno' ? $tr($o) : h($o)) . '</option>';
                // An answer whose option has since been renamed or removed is kept, and said so.
                if ($val !== '' && !in_array($val, $opts, true)) $out .= '<option value="' . h($val) . '" selected>' . h($val) . ' ' . $tr('(no longer on the list)') . '</option>';
                $out .= '</select>';
                break;
            default: $out .= '<input type="text" id="' . $id . '" name="' . $id . '" maxlength="255" value="' . h($val) . '" ' . $aria . '>';
        }
        $out .= field_error($id) . '</div>';
    }
    return $out;
}

/** A value as shown on a record. */
function custom_value_text(array $f, string $v): string {
    return $f['type'] === 'date' && $v !== '' ? fmt_date($v) : $v;
}

/** A record's answered fields as [label, value] pairs, in order (inactive fields too, where answered). */
function custom_fields_display(?int $clientId, ?int $referralId = null): array {
    $vals = custom_values_for($clientId, $referralId);
    $out = [];
    foreach (custom_fields_all() as $f) if (isset($vals[(int)$f['id']]) && $vals[(int)$f['id']] !== '') $out[] = [(string)$f['label'], custom_value_text($f, $vals[(int)$f['id']])];
    return $out;
}
