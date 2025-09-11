<?php
// lib/schema.php — validate LLM JSON against required fields per category
declare(strict_types=1);

function validate_regulation(array $reg, array &$notes): bool {
    $ok = true; $notes_local = [];
    $cat = $reg['category'] ?? '';
    $f = $reg['fields'] ?? [];
    if (!$cat || !in_array($cat, ['governance','contract','lawsuit'], true)) {
        $ok = false; $notes_local[] = 'regulation.category invalid';
    }
    if ($cat === 'governance') {
        foreach (['title','provider','source','date'] as $k) {
            if (empty($f[$k])) { $ok=false; $notes_local[]="reg.fields.$k missing"; }
        }
    } elseif ($cat === 'contract') {
        foreach (['title','contract_date','contract_type'] as $k) {
            if (empty($f[$k])) { $ok=false; $notes_local[]="reg.fields.$k missing"; }
        }
    } elseif ($cat === 'lawsuit') {
        foreach (['lawsuit_date','case_type','court'] as $k) {
            if (empty($f[$k])) { $ok=false; $notes_local[]="reg.fields.$k missing"; }
        }
    }
    $notes = array_merge($notes ?? [], $notes_local);
    return $ok;
}

function validate_asset(array $asset, array &$notes): bool {
    $ok = true; $notes_local = [];
    $cat = $asset['category'] ?? '';
    $f = $asset['fields'] ?? [];
    if (!$cat || !in_array($cat, ['data','model','agent'], true)) {
        $ok = false; $notes_local[] = 'asset.category invalid';
    }
    if ($cat === 'data') {
        foreach (['provider','dataset_name','release_date'] as $k) {
            if (empty($f[$k])) { $ok=false; $notes_local[]="asset.fields.$k missing"; }
        }
    } elseif ($cat === 'model') {
        foreach (['provider','model_name','release_date'] as $k) {
            if (empty($f[$k])) { $ok=false; $notes_local[]="asset.fields.$k missing"; }
        }
    } elseif ($cat === 'agent') {
        foreach (['provider','agent_name','release_date'] as $k) {
            if (empty($f[$k])) { $ok=false; $notes_local[]="asset.fields.$k missing"; }
        }
    }
    $notes = array_merge($notes ?? [], $notes_local);
    return $ok;
}
