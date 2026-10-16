<?php
/**
 * quiz_text_engine.php — turns lecture notes into well-formed quiz questions.
 *
 *  1. READ     qe_read_pdf() / qe_read_docx()  → document = titled sections of clean blocks
 *              (PDF words are positioned individually, so we rebuild lines from their
 *               x/y coordinates instead of gluing fragments in file order)
 *  2. UNDERSTAND qe_extract_facts()            → definitions, lists, statements
 *  3. ASK      qe_rule_questions()             → grammatical MCQs whose options are real
 *              terms/definitions from the same notes (no template filler)
 *  4. CHECK    qe_validate_questions()         → every question (rule-based OR AI) must pass
 *              structure checks; correct answers are shuffled across A–D
 *
 * Pure functions, no database access, so it can be tested from the command line.
 */

// ═════════════════════════════════════════════════════════════════════
//  1. READING
// ═════════════════════════════════════════════════════════════════════

function qe_to_utf8(string $s): string {
    if ($s === '') return '';
    if (preg_match('//u', $s) && preg_match('/[\x80-\xFF]/', $s)) return $s;          // already UTF-8
    if (function_exists('iconv')) {
        $o = @iconv('CP1252', 'UTF-8//TRANSLIT//IGNORE', $s);
        if ($o !== false) return $o;
    }
    return function_exists('utf8_encode') ? utf8_encode($s) : $s;
}

function qe_pdf_unescape(string $s): string {
    $out = ''; $n = strlen($s);
    for ($i = 0; $i < $n; $i++) {
        $c = $s[$i];
        if ($c !== '\\' || $i + 1 >= $n) { $out .= $c; continue; }
        $i++; $d = $s[$i];
        switch ($d) {
            case 'n': $out .= "\n"; break;
            case 'r': $out .= "\r"; break;
            case 't': $out .= "\t"; break;
            case 'b': case 'f': break;
            case '(': case ')': case '\\': $out .= $d; break;
            case "\r": if (($s[$i + 1] ?? '') === "\n") $i++; break;
            case "\n": break;
            default:
                if ($d >= '0' && $d <= '7') {
                    $oct = $d;
                    for ($k = 0; $k < 2 && isset($s[$i + 1]) && $s[$i + 1] >= '0' && $s[$i + 1] <= '7'; $k++) { $i++; $oct .= $s[$i]; }
                    $out .= chr(octdec($oct) & 0xFF);
                } else { $out .= $d; }
        }
    }
    return $out;
}

function qe_clean_text_chars(string $t): string {
    $t = strtr($t, ["\u{FB01}" => 'fi', "\u{FB02}" => 'fl', "\u{FB00}" => 'ff', "\u{FB03}" => 'ffi', "\u{FB04}" => 'ffl',
                    "\u{2019}" => "'", "\u{2018}" => "'", "\u{201C}" => '"', "\u{201D}" => '"', "\u{00A0}" => ' ',
                    "\u{2013}" => '-', "\u{2014}" => '-', "\u{2212}" => '-', "\u{2022}" => '', "\u{F0B7}" => '', "\u{F0A7}" => '']);
    return preg_replace('/[^\P{C}\n]+/u', ' ', $t) ?? $t;     // strip control chars
}

/** Walk every content stream and return, per page, a list of text runs [x, y, size, text]. */
function qe_pdf_pages(string $raw): array {
    $pages = []; $pos = 0; $len = strlen($raw);
    while (($s = strpos($raw, 'stream', $pos)) !== false) {
        $pos = $s + 6;
        if ($s >= 3 && substr($raw, $s - 3, 3) === 'end') continue;                  // "endstream"
        $before = rtrim(substr($raw, max(0, $s - 6), 6));
        if (substr($before, -2) !== '>>') continue;
        $objStart = strrpos(substr($raw, max(0, $s - 4000), 4000), 'obj');
        $dict = $objStart === false ? '' : substr($raw, max(0, $s - 4000) + $objStart, $s - (max(0, $s - 4000) + $objStart));
        $ds = $s + 6;
        if (($raw[$ds] ?? '') === "\r") $ds++;
        if (($raw[$ds] ?? '') === "\n") $ds++;
        $e = strpos($raw, 'endstream', $ds);
        if ($e === false) break;
        $data = substr($raw, $ds, $e - $ds);
        $pos = $e + 9;

        if (stripos($dict, '/FlateDecode') !== false) {
            $dec = @gzuncompress($data);
            if ($dec === false) $dec = @gzuncompress(rtrim($data, "\r\n"));
            if ($dec === false) $dec = @gzinflate(substr($data, 2));
            if ($dec === false) continue;
        } elseif (stripos($dict, '/Filter') !== false) {
            continue;
        } else { $dec = $data; }

        if (strpos($dec, 'BT') === false || strpos($dec, 'ET') === false) continue;
        $runs = qe_content_runs($dec);
        if ($runs) $pages[] = $runs;
    }
    return $pages;
}

function qe_content_runs(string $dec): array {
    $runs = [];
    $tokRe = '~(?<tm>(?:-?[\d.]+\s+){5}-?[\d.]+\s+Tm)|(?<td>-?[\d.]+\s+-?[\d.]+\s+T[dD])|(?<tstar>T\*)'
           . '|(?<tf>/[^\s/\[\]()<>]+\s+-?[\d.]+\s+Tf)|(?<tj>\((?:[^()\\\\]|\\\\.)*\)\s*Tj)'
           . '|(?<tjarr>\[(?:\((?:[^()\\\\]|\\\\.)*\)|[^\]()])*\]\s*TJ)|(?<hex><[0-9A-Fa-f\s]+>\s*Tj)~s';
    if (!preg_match_all('/BT(.*?)ET/s', $dec, $blocks)) return [];
    foreach ($blocks[1] as $blk) {
        $x = 0.0; $y = 0.0; $size = 12.0;
        if (!preg_match_all($tokRe, $blk, $toks, PREG_SET_ORDER)) continue;
        foreach ($toks as $t) {
            if (!empty($t['tm'])) {
                preg_match_all('/-?[\d.]+/', $t['tm'], $nums);
                $x = (float)$nums[0][4]; $y = (float)$nums[0][5];
            } elseif (!empty($t['td'])) {
                preg_match_all('/-?[\d.]+/', $t['td'], $nums);
                $x += (float)$nums[0][0]; $y += (float)$nums[0][1];
            } elseif (!empty($t['tstar'])) {
                $y -= $size * 1.2;
            } elseif (!empty($t['tf'])) {
                if (preg_match('/\s(-?[\d.]+)\s+Tf/', $t['tf'], $m)) $size = max(1.0, abs((float)$m[1]));
            } else {
                $txt = '';
                if (!empty($t['tj'])) {
                    if (preg_match('/^\((.*)\)\s*Tj$/s', $t['tj'], $m)) $txt = qe_pdf_unescape($m[1]);
                } elseif (!empty($t['tjarr'])) {
                    preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)|(-?\d+(?:\.\d+)?)/', $t['tjarr'], $parts, PREG_SET_ORDER);
                    foreach ($parts as $p) {
                        if (isset($p[1]) && $p[1] !== '') $txt .= qe_pdf_unescape($p[1]);
                        elseif (isset($p[2]) && $p[2] !== '' && (float)$p[2] < -180) $txt .= ' ';   // real word gap
                    }
                } elseif (!empty($t['hex'])) {
                    $h = preg_replace('/[^0-9A-Fa-f]/', '', $t['hex']);
                    if ($h !== '' && strlen($h) % 2 === 0) { $b = @hex2bin($h); if ($b !== false) $txt = $b; }
                }
                if ($txt === '') continue;
                $txt = qe_clean_text_chars(qe_to_utf8($txt));
                if (trim($txt) === '') { $x += $size * 0.3; continue; }
                $runs[] = ['x' => $x, 'y' => $y, 'size' => $size, 'text' => $txt];
                $x += mb_strlen($txt) * $size * 0.5;      // so later runs in the same BT stay to the right
            }
        }
    }
    return $runs;
}

/** Runs of one page → lines → paragraph blocks (+ the largest-font block as the title). */
function qe_page_blocks(array $runs): array {
    usort($runs, fn($a, $b) => ($b['y'] <=> $a['y']) ?: ($a['x'] <=> $b['x']));
    $lines = [];
    foreach ($runs as $r) {
        $li = count($lines) - 1;
        if ($li >= 0 && abs($lines[$li]['y'] - $r['y']) <= max(2.0, 0.4 * $lines[$li]['size'])) { $lines[$li]['runs'][] = $r; $lines[$li]['size'] = max($lines[$li]['size'], $r['size']); }
        else $lines[] = ['y' => $r['y'], 'size' => $r['size'], 'runs' => [$r]];
    }
    // split each line into column segments where there is a large horizontal gap (two-column slides / tables)
    $segLines = [];
    foreach ($lines as $ln) {
        usort($ln['runs'], fn($a, $b) => $a['x'] <=> $b['x']);
        $cur = ['y' => $ln['y'], 'size' => $ln['size'], 'runs' => []];
        $prevEnd = null;
        foreach ($ln['runs'] as $r) {
            if ($prevEnd !== null && ($r['x'] - $prevEnd) > 3.0 * max($r['size'], 8.0) && !empty($cur['runs'])) {
                $segLines[] = $cur; $cur = ['y' => $ln['y'], 'size' => $ln['size'], 'runs' => []];
            }
            $cur['runs'][] = $r;
            $prevEnd = $r['x'] + mb_strlen($r['text']) * $r['size'] * 0.5;
        }
        if (!empty($cur['runs'])) $segLines[] = $cur;
    }
    $lines = $segLines;
    $out = [];
    foreach ($lines as $ln) {
        usort($ln['runs'], fn($a, $b) => $a['x'] <=> $b['x']);
        $txt = '';
        foreach ($ln['runs'] as $i => $r) {
            if ($i > 0) {
                $needSpace = !(preg_match('/\s$/u', $txt) || preg_match('/^\s/u', $r['text']));
                if (preg_match('/(^|\s)\p{Lu}$/u', $txt) && preg_match('/^\p{Ll}{2,}/u', $r['text']) && mb_strlen(trim(end($ln['runs'])['text'] ?? '')) > 0 && mb_strlen(rtrim($txt)) >= 1 && ($ln['runs'][$i - 1]['text'] ?? '') !== '' && mb_strlen(trim($ln['runs'][$i - 1]['text'])) === 1) $needSpace = false;   // drop-cap: "A" + "ctuators"
                if ($needSpace && !preg_match('/^[.,;:!?)\]]/u', ltrim($r['text']))) $txt .= ' ';
            }
            $txt .= $r['text'];
        }
        $txt = trim(preg_replace('/\s+/u', ' ', $txt));
        $txt = preg_replace('/^(?:[-\x{2013}\x{2014}\x{2022}\x{00B7}*]\s*)+/u', '', $txt);        // bullet markers
        $txt = preg_replace('/(?<=\p{L}) - (?=\p{L})/u', '-', $txt);           // "real - world" -> "real-world"
        $txt = preg_replace("/(\\p{L}{2,}) (s|t|ll|re|ve|d)(?=[\\s.,;:!?)]|$)/u", "$1'$2", $txt);
        if ($txt === '') continue;
        $out[] = ['y' => $ln['y'], 'x' => $ln['runs'][0]['x'], 'size' => $ln['size'], 'text' => $txt];
    }
    // merge wrapped lines into blocks — compare with every open block so columns don't interleave
    usort($out, fn($p, $q) => ($q['y'] <=> $p['y']) ?: ($p['x'] <=> $q['x']));
    $blocks = [];
    foreach ($out as $ln) {
        $target = -1; $bestGap = INF;
        foreach ($blocks as $bi => $p) {
            $gap = $p['last_y'] - $ln['y'];
            if ($gap < 0) continue;
            $sameSize = abs($p['size'] - $ln['size']) <= 0.15 * max($p['size'], $ln['size']);
            $sameIndent = abs($p['x'] - $ln['x']) <= max(6.0, 0.8 * $ln['size']);
            $prevEnds = (bool)preg_match('/[.!?:]$/', $p['text']);
            $startsNew = (bool)preg_match('/^[A-Z0-9]/', $ln['text']);
            if ($sameSize && $sameIndent && $gap <= 1.9 * $ln['size'] && !($prevEnds && $startsNew) && !preg_match('/:$/', $p['text']) && $gap < $bestGap) {
                $target = $bi; $bestGap = $gap;
            }
        }
        if ($target >= 0) { $blocks[$target]['text'] .= ' ' . $ln['text']; $blocks[$target]['last_y'] = $ln['y']; }
        else $blocks[] = ['text' => $ln['text'], 'size' => $ln['size'], 'x' => $ln['x'], 'last_y' => $ln['y']];
    }
    return $blocks;
}

function qe_norm_key(string $s): string {
    return trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', '#', strtolower($s))));
}

/** Is this block junk (page number, date, slide chrome, math fragments)? */
function qe_is_junk_block(string $t): bool {
    $t = trim($t);
    if (mb_strlen($t) < 3) return true;
    if (preg_match('/^[\d\W_]+$/u', $t)) return true;
    if (preg_match('#^(page|slide)?\s*\d+(\s*(of|/)\s*\d+)?$#i', $t)) return true;
    if (preg_match('#\b\d{1,2}[/.-]\d{1,2}[/.-]\d{2,4}\b#', $t) && mb_strlen($t) < 24) return true;
    if (preg_match('/https?:|www\.|@\w+\.\w+|scan this code|copyright|\x{00A9}/iu', $t)) return true;
    if (preg_match('/\\\\[()\[\]]|->|=>|\\\\[a-z]+\{/', $t)) return true;                // LaTeX / arrows
    if (preg_match('/[\[\]{}]/', $t)) return true;                                          // tables / arrays / code fragments
    $letters = preg_match_all('/\p{L}/u', $t);
    if ($letters / max(1, mb_strlen($t)) < 0.55) return true;
    $words = preg_split('/\s+/', $t);
    if (count($words) <= 2 && $letters < 4) return true;
    if (count($words) >= 3 && count(array_filter($words, fn($w) => mb_strlen($w) <= 1)) / count($words) > 0.4) return true;   // "f P A f"
    return false;
}

function qe_read_pdf(string $path): array {
    $raw = @file_get_contents($path);
    if (!$raw) return ['sections' => [], 'text' => ''];
    $pages = qe_pdf_pages($raw);

    // 1) blocks per page + title by font size
    $pageData = []; $freq = [];
    foreach ($pages as $runs) {
        $blocks = qe_page_blocks($runs);
        $blocks = array_values(array_filter($blocks, fn($b) => !qe_is_junk_block($b['text'])));
        if (!$blocks) continue;
        $sizes = array_column($blocks, 'size'); sort($sizes);
        $median = $sizes[intdiv(count($sizes), 2)];
        $title = ''; $ti = -1; $maxSize = 0;
        foreach ($blocks as $i => $b) if ($b['size'] > $maxSize) { $maxSize = $b['size']; $ti = $i; }
        if ($ti >= 0 && $maxSize >= 1.25 * $median && str_word_count($blocks[$ti]['text']) <= 14) {
            $title = $blocks[$ti]['text']; array_splice($blocks, $ti, 1);
        }
        $texts = array_column($blocks, 'text');
        foreach (array_unique(array_map('qe_norm_key', array_merge($texts, [$title]))) as $k) $freq[$k] = ($freq[$k] ?? 0) + 1;
        $pageData[] = ['title' => $title, 'blocks' => $texts];
    }
    // 2) drop running headers/footers (same text on many pages)
    $repeatLimit = max(3, (int)ceil(count($pageData) * 0.3));
    $sections = []; $byTitle = [];
    foreach ($pageData as $pd) {
        $blocks = array_values(array_filter($pd['blocks'], fn($t) => ($freq[qe_norm_key($t)] ?? 0) < $repeatLimit));
        $title = $pd['title'];
        if ($title !== '' && ($freq[qe_norm_key($title)] ?? 0) >= $repeatLimit && count($pageData) > 6) {
            // a title repeated on most pages is a running header, not a topic
            $title = '';
        }
        $key = strtolower($title);
        if ($title !== '' && isset($byTitle[$key])) {                      // same slide title continued over several pages
            foreach ($blocks as $b) if (!in_array($b, $sections[$byTitle[$key]]['blocks'], true)) $sections[$byTitle[$key]]['blocks'][] = $b;
        } else {
            $sections[] = ['title' => $title, 'blocks' => $blocks];
            if ($title !== '') $byTitle[$key] = count($sections) - 1;
        }
    }
    $sections = array_values(array_filter($sections, fn($s) => count($s['blocks']) > 0));
    return ['sections' => $sections, 'text' => qe_flatten($sections)];
}

function qe_read_docx(string $path): array {
    if (!class_exists('ZipArchive')) return ['sections' => [], 'text' => ''];
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return ['sections' => [], 'text' => ''];
    $xml = $zip->getFromName('word/document.xml'); $zip->close();
    if (!$xml) return ['sections' => [], 'text' => ''];
    preg_match_all('/<w:p[ >].*?<\/w:p>/s', $xml, $paras);
    $sections = []; $cur = ['title' => '', 'blocks' => []];
    foreach ($paras[0] as $p) {
        $p2 = preg_replace(['/<w:tab\/>/', '/<w:br[^>]*\/>/'], ' ', $p);
        preg_match_all('/<w:t[^>]*>(.*?)<\/w:t>/s', $p2, $tm);
        $text = trim(preg_replace('/\s+/u', ' ', qe_clean_text_chars(html_entity_decode(implode('', $tm[1]), ENT_QUOTES | ENT_XML1, 'UTF-8'))));
        if ($text === '' || qe_is_junk_block($text)) continue;
        $isHeading = (bool)preg_match('/<w:pStyle w:val="(Heading\d|Title|Subtitle)"/i', $p);
        if ($isHeading && str_word_count($text) <= 14) {
            if ($cur['blocks'] || $cur['title'] !== '') $sections[] = $cur;
            $cur = ['title' => $text, 'blocks' => []];
        } else $cur['blocks'][] = $text;
    }
    if ($cur['blocks'] || $cur['title'] !== '') $sections[] = $cur;
    $sections = array_values(array_filter($sections, fn($s) => count($s['blocks']) > 0));
    return ['sections' => $sections, 'text' => qe_flatten($sections)];
}

function qe_flatten(array $sections, int $maxChars = 0): string {
    $out = '';
    foreach ($sections as $s) {
        if ($s['title'] !== '') $out .= "## " . $s['title'] . "\n";
        foreach ($s['blocks'] as $b) $out .= "- " . $b . "\n";
        $out .= "\n";
    }
    $out = trim($out);
    if ($maxChars > 0 && mb_strlen($out) > $maxChars) {
        // keep an even slice of EVERY section instead of only the first pages
        $per = max(200, (int)floor($maxChars / max(1, count($sections))));
        $out = '';
        foreach ($sections as $s) {
            $chunk = ($s['title'] !== '' ? "## " . $s['title'] . "\n" : '');
            foreach ($s['blocks'] as $b) { if (mb_strlen($chunk) + mb_strlen($b) + 3 > $per) break; $chunk .= "- " . $b . "\n"; }
            $out .= $chunk . "\n";
        }
        $out = trim(mb_substr($out, 0, $maxChars));
    }
    return $out;
}

/** Does this look like readable prose (not binary/garbage)? */
function qe_looks_like_text(string $text): bool {
    if (strlen(trim($text)) < 120) return false;
    $words = preg_split('/\s+/', trim($text));
    if (count($words) < 25) return false;
    $ok = 0;
    foreach ($words as $w) { $w = trim($w, ".,;:!?()[]{}\"'-#"); if ($w !== '' && preg_match('/^[A-Za-z]{2,}$/', $w)) $ok++; }
    return $ok / count($words) >= 0.55;
}

// ═════════════════════════════════════════════════════════════════════
//  2. UNDERSTANDING — facts
// ═════════════════════════════════════════════════════════════════════

function qe_wc(string $s): int { return count(preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY)); }
function qe_sentence_case(string $s): string {
    $s = trim($s); if ($s === '') return $s;
    return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);
}
function qe_strip_end(string $s): string { return rtrim(trim($s), " .;:,"); }
function qe_trim_words(string $s, int $maxWords = 24): string {
    $w = preg_split('/\s+/', trim($s));
    if (count($w) <= $maxWords) return trim($s);
    $cut = implode(' ', array_slice($w, 0, $maxWords));
    // end at a natural boundary if we can
    if (preg_match('/^(.{40,}?[.;])\s/u', $cut . ' ', $m)) return rtrim($m[1], ';');
    return rtrim($cut, ',;:') . '…';
}
function qe_is_title_like(string $b): bool {
    $b = trim($b);
    if ($b === '' || preg_match('/[.!?]$/', $b)) return false;
    $n = qe_wc(rtrim($b, ':'));
    return $n >= 1 && $n <= 6 && preg_match('/^[A-Z\d]/', $b) && !preg_match('/\b(is|are|was|were|can|will|may|should)\b/i', $b);
}
function qe_term_ok(string $t): bool {
    $t = trim($t);
    if ($t === '' || mb_strlen($t) < 3 || mb_strlen($t) > 60) return false;
    $n = qe_wc($t);
    if ($n > 6) return false;
    if (preg_match('/^(it|this|that|these|those|they|there|he|she|we|you|which|what|who|when|where|how|also|but|and|or|if|so)\b/i', $t)) return false;
    return (bool)preg_match('/\p{L}/u', $t);
}

const QE_BAD_TERMS = ['learning objectives','objectives','outline','agenda','summary','references','reference','questions','any questions',
    'introduction','conclusion','overview','contents','recap','revision','homework','assignment','thank you','example','examples','note','notes',
    'goal','recommendation','extremes','purpose','solution','problem','answer','result','results','definition','advantages','disadvantages',
    'student exercise','exercise','exercises','activity','activities','task','tasks','discussion','question','benefits','types','methods',
    'presentation outline','further reading','class discussion','group project','case study','reading assignment','key takeaways','takeaways'];

function qe_def_complete(string $def): bool {
    $def = trim($def);
    if (strpos($def, '?') !== false) return false;
    if (preg_match('/\b(following|below|above|these|those|such as)\s*[.:]?$/i', trim($def))) return false;   // "can be either one of the following"
    if (preg_match('/^(download|skim|click|visit|see|refer|review|attend|submit|bring|prepare|form|join|create|build|draw|start|begin|open|go|check|ensure|make|take|find|select|use|try|think|remember|note|ask|work|discuss)\b/i', $def)) return false;
                                              // exam prompts, not definitions
    if (preg_match('/^(what|why|how|which|who|when|where|discuss|explain|identify|state|list|describe|outline|read|watch|study|complete|consider|answer|write|define|compare)\b/i', $def)) return false;
    if (preg_match('/\(\s*(19|20)\d\d\s*\)|\bpp?\.\s*\d|\bet al\b|\bed\.\)|\bISBN\b|\bdoi\b/i', $def)) return false;  // citations
    if (qe_wc($def) >= 4) {                                                                       // Title Case heading, not a sentence
        $wds = preg_split('/\s+/', $def); $cap = 0; foreach ($wds as $w) if (preg_match('/^\p{Lu}/u', $w)) $cap++;
        if ($cap / count($wds) >= 0.45) return false;
    }
    if (preg_match('/\w:\s+\S/', $def)) return false;
    if (preg_match('/(^|[\s;])\d+[.)]\s/', $def)) return false;                            // "1. Identify ..." exercise lists
    $caps = preg_match_all('/(?<=\s)\p{Lu}\p{Ll}{2,}/u', $def);
    if ($caps >= 3 && !preg_match('/[.;,]/', $def)) return false;                            // table headers glued together
                                // another "Label:" glued inside
    $toks = preg_split('/[^a-z]+/', strtolower($def), -1, PREG_SPLIT_NO_EMPTY);          // same long word again within 5 words =
    foreach ($toks as $i => $w) {                                                          // two columns interleaved ("previous previous")
        if (strlen($w) < 5) continue;
        for ($j = $i + 1; $j <= $i + 5 && $j < count($toks); $j++) if ($toks[$j] === $w) return false;
    }
    if (substr_count($def, '(') !== substr_count($def, ')')) return false;
    $w = preg_split('/\s+/', rtrim($def, " .;:,"));
    $last = strtolower(end($w));
    return !in_array($last, ['of','an','a','the','to','and','or','in','on','for','with','by','that','which','is','are','as','from','at','its','their','be','e.g','i.e','eg','ie'], true)
        && qe_wc($def) >= 4 && preg_match_all('/\b[a-z]{2,}\b/', $def) >= 3;
}
function qe_good_term(string $t): bool {
    $t = trim($t);
    if (!qe_term_ok($t)) return false;
    if (preg_match('/\d/', $t) || qe_wc($t) > 5) return false;
    if (preg_match('/\b\p{Lu} \p{Ll}{3,}/u', $t)) return false;                       // split drop-cap ("A ctuators")
    if (preg_match('/^(e\.g|i\.e|etc|vs)\b|\b(e\.g|i\.e)\b|\bvs\.?\b/i', $t)) return false;   // "e.g. a gaming environment"
    if (in_array(strtolower($t), QE_BAD_TERMS, true)) return false;
    if (preg_match('/^(references?|answer|discuss|explain|identify|state|list|describe|outline|read|watch|study|complete|consider|write|define|compare|exercise|question|activity|case study)\b/i', $t)) return false;
    if (preg_match('/\b(definition of|part|chapter|topic|week|lecture|slide)\b/i', $t)) return false;
    $w = preg_split('/\s+/', $t); $last = strtolower(end($w)); $first = strtolower($w[0]);
    $dangling = ['for','of','to','and','or','in','on','with','by','the','a','an','as','at','from','is','are','that','which','vs','-','who','whose','whom','whether','when','where','if','because','than','but'];
    if (in_array($last, $dangling, true) || in_array($first, ['and','or','of','to','for','with','by'], true)) return false;   // "Design PEAS frameworks for"
    if (preg_match('/[:+\/&()=<>]/', $t)) return false;                                  // "Order +", "Business Processes: SOP Constraints"
    if (preg_match('/\s-\s/', $t)) return false;                                         // "Single - agent Multi - agent"
    if (preg_match('/\b[IVX]{2,}$/', $t) || preg_match('/\s[A-Z]$/', $t)) return false;             // "Imprecise Requirements II"
    if (qe_wc($t) >= 3 && preg_match('/\b(and|or)\b/i', $t)) return false;                // "Completeness and Consistency"
    if (preg_match('/\p{Ll}\s+\p{Lu}\p{Ll}+\s+\p{Lu}/u', $t)) return false;                // run-together headings
    return true;
}
/** Remove a repeated slide title from the start of a block ("An Agent: Definition of Terms Agent program: ..."). */
function qe_strip_title_prefix(string $b, array $titles): string {
    foreach ($titles as $t) {
        if ($t !== '' && mb_strlen($b) > mb_strlen($t) + 8 && stripos($b, $t) === 0) {
            $rest = ltrim(mb_substr($b, mb_strlen($t)), " :-");
            if ($rest !== '') return $rest;
        }
    }
    return $b;
}

/**
 * Returns ['defs'=>[...], 'lists'=>[...], 'stmts'=>[...], 'terms'=>[...]]
 *  defs  : term + definition (+ section title)
 *  lists : category + items (≥3)
 *  stmts : standalone well-formed sentences (for fill-the-gap questions)
 *  terms : lexicon of real domain terms (definition terms, list items, section titles)
 */
function qe_extract_facts(array $sections): array {
    $defs = []; $lists = []; $stmts = []; $terms = [];
    $seenDef = [];
    $titles = array_values(array_unique(array_filter(array_map(fn($x) => $x['title'], $sections))));
    usort($titles, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));          // longest title first
    foreach ($sections as $sec) {
        $title = $sec['title'];
        $blocks = [];
        foreach ($sec['blocks'] as $b) {
            $b = qe_strip_title_prefix($b, $titles);
            if (in_array(strtolower(rtrim($b, ':. ')), array_map('strtolower', $titles), true)) continue;   // block is just another slide title
            foreach (preg_split('/\s[-\x{2013}\x{2022}]\s+(?=[A-Z])/u', $b) as $part) {     // bullets merged into one block
                $part = trim($part, " -\x{2013}\x{2022}");
                if ($part !== '') $blocks[] = $part;
            }
        }
        $blocks = array_values($blocks);
        $n = count($blocks);
        $i = 0; $run = [];   // run of consecutive short title-like blocks (list candidates)
        $runCat = '';
        $flushRun = function () use (&$run, &$lists, $title, &$terms, &$runCat) {
            if (count($run) >= 3) {
                $items = [];
                foreach ($run as $k2 => $r) {
                    if (!is_int($k2) || !is_string($r)) continue;
                    $r = qe_strip_end(trim(preg_replace('/\s*\([^)]*\)\s*/', ' ', $r)));
                    if ($r === '' || !qe_good_term($r)) continue;
                    $items[] = $r;
                }
                $uniq = array_values(array_unique(array_map('strtolower', $items)));
                if (count($uniq) < count($items) * 0.8) { $run = []; return; }          // repeated labels = diagram boxes, not a list
                $items = array_values(array_intersect_key($items, array_unique(array_map('strtolower', $items))));
                if (count($items) < 4) { $run = []; return; }
                $cat = $runCat !== '' ? $runCat : (($title !== '' && qe_wc($title) <= 5 && !preg_match('/^(an?|the)\s/i', $title)) ? $title : '');
                $cat = rtrim(trim($cat), ':');
                if ($cat !== '' && qe_good_term($cat) && qe_wc($cat) <= 6 || ($cat !== '' && preg_match('/^[A-Za-z][A-Za-z ,\'-]{3,50}$/', $cat) && !preg_match('/[:+\/&]/', $cat))) $lists[] = ['category' => $cat, 'items' => $items, 'section' => $title];
            }
            $run = [];
        };
        for ($i = 0; $i < $n; $i++) {
            $b = $blocks[$i];

            // "Term: definition"
            if (preg_match('/^([^:]{3,60}?)\s*:\s+(.{14,})$/u', $b, $m) && qe_good_term($m[1]) && qe_def_complete($m[2])) {
                $term = qe_strip_end($m[1]); $def = qe_strip_end($m[2]);
                $k = strtolower($term);
                if (!isset($seenDef[$k])) { $seenDef[$k] = 1; $defs[] = ['term' => $term, 'def' => $def, 'section' => $title]; $terms[] = $term; }
                $flushRun(); continue;
            }
            // "Heading:" followed by its explanation block(s)
            if (preg_match('/^(.{3,60}?)\s*:$/u', $b, $m) && qe_good_term($m[1])) {
                $body = []; $j = $i + 1;
                while ($j < $n && !preg_match('/:$/', $blocks[$j]) && count($body) < 3) { $body[] = $blocks[$j]; $j++; }
                if ($body) {
                    $def = qe_strip_end(implode('; ', array_map('qe_strip_end', $body)));
                    $term = qe_strip_end($m[1]); $k = strtolower($term);
                    if (qe_def_complete($def) && !isset($seenDef[$k])) { $seenDef[$k] = 1; $defs[] = ['term' => $term, 'def' => $def, 'section' => $title]; $terms[] = $term; }
                    $i = $j - 1; $flushRun(); continue;
                }
            }
            // "X is / are / means / refers to Y."
            if (preg_match('/^(?:An?\s+|The\s+)?([A-Z][^,.;:]{2,60}?)\s+(is|are|means|refers to|can be defined as|is defined as)\s+(.{12,})$/u', $b, $m)
                && qe_good_term($m[1]) && qe_def_complete($m[3])) {
                $term = qe_strip_end(preg_replace('/^(An?|The)\s+/i', '', $m[1])); $k = strtolower($term);
                $def = qe_strip_end($m[3]);
                if (!isset($seenDef[$k]) && stripos($def, $term) === false) { $seenDef[$k] = 1; $defs[] = ['term' => $term, 'def' => $def, 'section' => $title, 'verb' => $m[2], 'article' => stripos($b, 'an ') === 0 ? 'An ' : (stripos($b, 'a ') === 0 ? 'A ' : '')]; $terms[] = $term; }
                $flushRun();
            }
            // title-like block followed by a descriptive block → term + definition
            if (qe_is_title_like($b) && $i + 1 < $n && qe_wc($blocks[$i + 1]) >= 6 && !qe_is_title_like($blocks[$i + 1])) {
                $term = qe_strip_end($b); $k = strtolower($term);
                $body = [$blocks[$i + 1]]; $j = $i + 2;
                while ($j < $n && !qe_is_title_like($blocks[$j]) && qe_wc($blocks[$j]) >= 3 && count($body) < 3) { $body[] = $blocks[$j]; $j++; }
                $def = qe_strip_end(implode(' ', $body));
                if (!isset($seenDef[$k]) && qe_good_term($term) && qe_def_complete($def) && stripos($def, $term) === false) { $seenDef[$k] = 1; $defs[] = ['term' => $term, 'def' => $def, 'section' => $title]; $terms[] = $term; }
                $i = $j - 1; $flushRun(); continue;
            }
            // list member candidates
            if (qe_is_title_like($b) && qe_wc($b) <= 5) {
                if (empty($run)) { $pb = $i > 0 ? qe_strip_end($blocks[$i - 1]) : ''; $runCat = ($pb !== '' && qe_wc($pb) <= 8 && !preg_match('/[.!?]$/', $blocks[$i - 1] ?? '')) ? $pb : ''; }
                $run[] = $b; continue;
            }
            $flushRun();
            // standalone statements — one sentence at a time
            foreach (preg_split('/(?<=[.!?])\s+(?=[A-Z])/u', $b) as $sent) {
                $sent = trim($sent); $wc = qe_wc($sent);
                if ($wc >= 8 && $wc <= 32 && preg_match('/^[A-Z]/', $sent) && !preg_match('/:$/', $sent)
                    && !preg_match('/^(it|this|these|those|they|he|she|we|there|here)\b/i', $sent) && qe_def_complete($sent)) {
                    $stmts[] = ['text' => qe_strip_end($sent), 'section' => $title];
                }
            }
        }
        $flushRun();
    }
    // slide titles are real topic names ("Value Chains", "Root Cause Analysis")
    foreach ($sections as $sec) {
        $t = qe_strip_end($sec['title']);
        if ($t !== '' && qe_wc($t) <= 4 && !preg_match('/^(an?|the)\s/i', $t)) $terms[] = $t;
    }
    // Title-Case phrases that recur in the notes are key terms too
    $freq = [];
    foreach ($sections as $sec) {
        foreach (array_merge([$sec['title']], $sec['blocks']) as $txt) {
            if (preg_match_all('/\b(?:[A-Z][a-z]+|[A-Z]{2,})(?:\s+(?:[A-Z][a-z]+|[A-Z]{2,})){1,3}\b/u', (string)$txt, $mm)) {
                foreach ($mm[0] as $ph) $freq[$ph] = ($freq[$ph] ?? 0) + 1;
            }
        }
    }
    foreach ($freq as $ph => $c) {
        if ($c < 2 || !qe_good_term($ph)) continue;
        if (preg_match('/^(an?|the|why|how|what|based|added|enabled|related|class|group|activity|reading|case|problem)\b/i', $ph)) continue;   // fragments / slide labels
        $terms[] = $ph;
    }
    $corpus = strtolower(implode(' ', array_merge(array_column($stmts, 'text'), array_column($defs, 'def'))));
    $defTermSet = array_map('strtolower', array_column($defs, 'term'));
    $terms = array_values(array_unique(array_filter($terms, function ($t) use ($corpus, $defTermSet) {
        if (!qe_good_term($t)) return false;
        if (preg_match('/\b(limited|ltd|inc|corp|llc|university|school|department|faculty|institute|college)\b/i', $t)) return false;   // organisations
        if (in_array(strtolower($t), $defTermSet, true)) return true;                      // explicitly defined → keep
        return strpos($corpus, strtolower($t)) !== false;                                  // otherwise it must occur in a real sentence
    })));
    return ['defs' => $defs, 'lists' => $lists, 'stmts' => $stmts, 'terms' => $terms];
}

// ═════════════════════════════════════════════════════════════════════
//  3. ASKING — rule-based question builder
// ═════════════════════════════════════════════════════════════════════

function qe_pick_distractors(array $pool, string $correct, int $n, string $difficulty, ?int $seed = null): array {
    $c = qe_strip_end($correct);
    $pool = array_values(array_unique(array_filter($pool, function ($p) use ($c) {
        $p = qe_strip_end($p);
        if ($p === '' || strcasecmp($p, $c) === 0) return false;
        // a distractor that contains (or is contained in) the answer would also be "correct" ("Human agent" vs "agent")
        if (mb_strlen($c) <= 40 && mb_strlen($p) <= 40 && (stripos($p, $c) !== false || stripos($c, $p) !== false)) return false;
        return true;
    })));
    if (count($pool) < $n) return [];
    // beginner: any distractors; advanced: the ones most similar in length (hardest to rule out)
    $clen = mb_strlen($correct);
    if ($difficulty === 'advanced') usort($pool, fn($a, $b) => abs(mb_strlen($a) - $clen) <=> abs(mb_strlen($b) - $clen));
    else {
        mt_srand(crc32($correct . ($seed ?? 0)));
        for ($i = count($pool) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]]; }
        if ($difficulty === 'intermediate') usort($pool, fn($a, $b) => abs(mb_strlen($a) - $clen) <=> abs(mb_strlen($b) - $clen) ?: 0);
    }
    return array_slice($pool, 0, $n);
}

function qe_make_q(string $stem, string $correct, array $wrong, string $explanation): ?array {
    if (count($wrong) < 3) return null;
    $opts = array_merge([$correct], array_slice($wrong, 0, 3));
    return ['question_text' => $stem, 'options' => $opts, 'explanation' => $explanation];   // options[0] is correct
}

function qe_stem(string $type, string $kind, string $x): string {
    $x = trim($x);
    $q = [
        'def_to_term' => [
            'theory'    => "Which term is defined as: \"%s\"?",
            'practical' => "Which concept best fits this description: \"%s\"?",
            'aptitude'  => "Which term does the following description refer to? \"%s\"",
        ],
        'term_to_def' => [
            'theory'    => "What is meant by \"%s\"?",
            'practical' => "Which statement best describes \"%s\"?",
            'aptitude'  => "Which of the following correctly explains \"%s\"?",
        ],
        'list_member' => [
            'theory'    => "Which of the following is listed under \"%s\"?",
            'practical' => "Which of these would you identify as part of \"%s\"?",
            'aptitude'  => "Which one of these belongs to \"%s\"?",
        ],
        'list_not' => [
            'theory'    => "Which of the following is NOT listed under \"%s\"?",
            'practical' => "Which of these is NOT part of \"%s\"?",
            'aptitude'  => "Which one of these does NOT belong to \"%s\"?",
        ],
        'cloze' => [
            'theory'    => "Complete the statement: \"%s\"",
            'practical' => "Choose the term that correctly completes this statement: \"%s\"",
            'aptitude'  => "Which option fills the gap so the statement is correct? \"%s\"",
        ],
    ];
    $t = $q[$kind][$type] ?? $q[$kind]['theory'];
    return sprintf($t, $x);
}

/** Build one question from a fact. $F = all facts. Returns null if it can't be made well. */
function qe_build_from_fact(array $fact, array $F, string $type, string $difficulty, int $salt, int $defIndex = 0): ?array {
    $allTerms = $F['terms'];
    $defTexts = array_map(fn($d) => qe_trim_words($d['def'], 22), $F['defs']);

    if ($fact['kind'] === 'def') {
        $d = $fact['data'];
        $term = $d['term']; $def = qe_trim_words($d['def'], 26);
        $sameSecTerms = array_values(array_unique(array_map(fn($x) => $x['term'], array_filter($F['defs'], fn($x) => $x['section'] === $d['section'] && $x['term'] !== $term))));
        // beginner starts with description->term, advanced with term->description; both then alternate
        $variant = ($difficulty === 'advanced') ? (1 - ($defIndex % 2)) : ($defIndex % 2);
        if ($variant === 0) {   // description → term
            $pool = ($difficulty === 'advanced' && count($sameSecTerms) >= 3) ? array_merge($sameSecTerms, $allTerms) : $allTerms;
            $wrong = qe_pick_distractors($pool, $term, 3, $difficulty, $salt);
            if (count($wrong) < 3) return null;
            return qe_make_q(qe_stem($type, 'def_to_term', qe_sentence_case($def)), qe_sentence_case($term),
                             array_map('qe_sentence_case', $wrong), "From the notes: " . $term . " — " . rtrim($def, ".") . ".");
        }
        // term → description
        $wrong = qe_pick_distractors($defTexts, $def, 3, $difficulty, $salt);
        if (count($wrong) < 3) return null;
        return qe_make_q(qe_stem($type, 'term_to_def', $term), qe_sentence_case($def),
                         array_map('qe_sentence_case', $wrong), "From the notes: " . $term . " — " . rtrim($def, ".") . ".");
    }

    if ($fact['kind'] === 'list') {
        $L = $fact['data']; $items = $L['items'];
        $cat = qe_strip_end($L['category']);
        $others = [];
        foreach ($F['lists'] as $o) if ($o !== $L) foreach ($o['items'] as $it) $others[] = $it;
        $others = array_merge($others, array_filter($allTerms, fn($t) => !in_array($t, $items, true)));
        $others = array_values(array_unique(array_filter($others, fn($t) => !in_array($t, $items, true))));
        $mode = ($difficulty === 'advanced' && count($items) >= 4) ? 'not' : (($difficulty === 'intermediate' && count($items) >= 4 && $salt % 2 === 0) ? 'not' : 'member');
        if ($mode === 'not' && count($others) >= 1) {
            mt_srand(crc32($cat . $salt)); $pick = $items; shuffle($pick);
            $three = array_slice($pick, 0, 3);
            $odd = $others[$salt % count($others)];
            return qe_make_q(qe_stem($type, 'list_not', $cat), qe_sentence_case($odd), array_map('qe_sentence_case', $three),
                             "The notes list under \"$cat\": " . implode(', ', $items) . ".");
        }
        if (count($others) < 3) return null;
        $correct = $items[$salt % count($items)];
        $catWords = array_filter(preg_split('/[^a-z]+/', strtolower($cat)), fn($w) => strlen($w) >= 5);
        $others = array_values(array_filter($others, function ($o) use ($catWords) {
            foreach ($catWords as $w) if (stripos($o, rtrim($w, 's')) !== false) return false;
            return true;
        }));
        $wrong = qe_pick_distractors($others, $correct, 3, $difficulty, $salt);
        if (count($wrong) < 3) return null;
        return qe_make_q(qe_stem($type, 'list_member', $cat), qe_sentence_case($correct), array_map('qe_sentence_case', $wrong),
                         "The notes list under \"$cat\": " . implode(', ', $items) . ".");
    }

    if ($fact['kind'] === 'stmt') {
        $s = $fact['data']['text'];
        // blank out a real domain term (never a random word)
        $best = null;
        foreach ($allTerms as $t) {
            if (mb_strlen($t) < 4) continue;
            if (preg_match('/\b' . preg_quote($t, '/') . '\b/iu', $s) && ($best === null || mb_strlen($t) > mb_strlen($best))) $best = $t;
        }
        if ($best === null) return null;
        $gap = preg_replace('/\b' . preg_quote($best, '/') . '\b/iu', '_____', $s, 1);
        if (substr_count($gap, '_____') !== 1) return null;
        if (preg_match('/^_____/', $gap)) return null;                                   // blank at the very start reads badly

        $wrong = qe_pick_distractors($allTerms, $best, 3, $difficulty, $salt);
        if (count($wrong) < 3) return null;
        return qe_make_q(qe_stem($type, 'cloze', $gap), qe_sentence_case($best), array_map('qe_sentence_case', $wrong), "From the notes: " . rtrim($s, ".") . ".");
    }
    return null;
}

/**
 * Rule-based generation from a parsed document. Every category (type × difficulty)
 * starts at a different place in the fact pool; within one quiz no fact is reused.
 */
function qe_rule_questions(array $doc, string $quiz_type, string $difficulty, int $num): array {
    $F = qe_extract_facts($doc['sections']);
    $facts = [];
    foreach ($F['defs']  as $d) $facts[] = ['kind' => 'def',  'data' => $d, 'len' => qe_wc($d['def'])];
    foreach ($F['lists'] as $l) $facts[] = ['kind' => 'list', 'data' => $l, 'len' => count($l['items']) * 2];
    foreach ($F['stmts'] as $s) $facts[] = ['kind' => 'stmt', 'data' => $s, 'len' => qe_wc($s['text'])];
    if (count($facts) < 3) return [];

    $difficulty = in_array($difficulty, ['beginner', 'intermediate', 'advanced'], true) ? $difficulty : 'intermediate';
    // which kinds each category prefers (first = most wanted)
    $pref = [
        'theory'    => ['beginner' => ['def', 'list', 'stmt'], 'intermediate' => ['def', 'list', 'stmt'], 'advanced' => ['stmt', 'def', 'list']],
        'practical' => ['beginner' => ['list', 'def', 'stmt'], 'intermediate' => ['def', 'stmt', 'list'], 'advanced' => ['list', 'stmt', 'def']],
        'aptitude'  => ['beginner' => ['def', 'stmt', 'list'], 'intermediate' => ['stmt', 'list', 'def'], 'advanced' => ['stmt', 'def', 'list']],
    ];
    $order = $pref[$quiz_type][$difficulty] ?? ['def', 'list', 'stmt'];
    usort($facts, function ($a, $b) use ($order, $difficulty) {
        $ka = array_search($a['kind'], $order); $kb = array_search($b['kind'], $order);
        if ($ka !== $kb) return $ka <=> $kb;
        return $difficulty === 'beginner' ? $a['len'] <=> $b['len'] : ($difficulty === 'advanced' ? $b['len'] <=> $a['len'] : 0);
    });
    // interleave kinds so a quiz mixes question styles instead of 5 of one kind
    $byKind = ['def' => [], 'list' => [], 'stmt' => []];
    foreach ($facts as $f) $byKind[$f['kind']][] = $f;
    $mixed = []; $weights = ['def' => 0, 'list' => 0, 'stmt' => 0];
    $cycle = $order; $guard = 0;
    while (array_sum(array_map('count', $byKind)) > 0 && $guard++ < 5000) {
        foreach ($cycle as $k) if (!empty($byKind[$k])) $mixed[] = array_shift($byKind[$k]);
    }

    $combo = ['theory' => 0, 'practical' => 3, 'aptitude' => 6][$quiz_type] ?? 0;
    $combo += ['beginner' => 0, 'intermediate' => 1, 'advanced' => 2][$difficulty];
    $n = count($mixed);
    $start = intdiv($combo * $n, 9);

    $questions = []; $usedStems = []; $tries = 0; $defCount = 0;
    for ($i = 0; $i < $n && count($questions) < $num && $tries < $n + 5; $i++) {
        $f = $mixed[($start + $i) % $n]; $tries++;
        $q = qe_build_from_fact($f, $F, $quiz_type, $difficulty, $i + $combo, $defCount);
        if (!$q) continue;
        if ($f['kind'] === 'def') $defCount++;
        $key = strtolower($q['question_text']);
        if (isset($usedStems[$key])) continue;
        $usedStems[$key] = true;
        $questions[] = $q;
    }
    return $questions;
}

// ═════════════════════════════════════════════════════════════════════
//  4. CHECKING — validate + normalise any question list (AI or rule-based)
// ═════════════════════════════════════════════════════════════════════

function qe_words_set(string $s): array {
    preg_match_all('/[a-z0-9]{3,}/', strtolower($s), $m);
    return array_unique($m[0]);
}
function qe_similarity(string $a, string $b): float {
    $A = qe_words_set($a); $B = qe_words_set($b);
    if (!$A || !$B) return 0.0;
    return count(array_intersect($A, $B)) / count(array_unique(array_merge($A, $B)));
}

/**
 * Accepts questions in either shape:
 *   rule-based : ['question_text', 'options'=>[correct, w1, w2, w3], 'explanation']
 *   AI / DB    : ['question_text','option_a'..'option_d','correct_option','explanation']
 * Returns DB-shaped questions, correct answer shuffled, invalid ones dropped.
 */
function qe_validate_questions(array $qs, int $limit = 50): array {
    $out = []; $letters = ['A', 'B', 'C', 'D'];
    foreach ($qs as $q) {
        if (!is_array($q)) continue;
        $stem = trim((string)($q['question_text'] ?? ''));
        if (isset($q['options']) && is_array($q['options'])) { $opts = array_values($q['options']); $correctIdx = 0; }
        else {
            $opts = [trim((string)($q['option_a'] ?? '')), trim((string)($q['option_b'] ?? '')), trim((string)($q['option_c'] ?? '')), trim((string)($q['option_d'] ?? ''))];
            $ci = array_search(strtoupper(trim((string)($q['correct_option'] ?? ''))), $letters, true);
            if ($ci === false) continue;
            $correctIdx = $ci;
        }
        if (count($opts) !== 4) continue;
        $opts = array_map(fn($o) => trim(preg_replace('/\s+/u', ' ', (string)$o)), $opts);
        $opts = array_map(fn($o) => preg_replace('/^[A-Da-d][\).:]\s+/', '', $o), $opts);          // "A) text" → "text"
        if (mb_strlen($stem) < 12 || mb_strlen($stem) > 420) continue;
        $bad = false; $norm = [];
        foreach ($opts as $o) {
            if ($o === '' || mb_strlen($o) > 240) { $bad = true; break; }
            if (preg_match('/^(all|none) of the (above|these)\b|^both\b.*\band\b.*$/i', $o)) { $bad = true; break; }
            $norm[] = strtolower(rtrim($o, " ."));
        }
        if ($bad || count(array_unique($norm)) < 4) continue;
        if (in_array(strtolower(rtrim($stem, " ?.")), $norm, true)) continue;
        // answer must not be given away by being inside the question verbatim (long answers only)
        $correct = $opts[$correctIdx];
        if (mb_strlen($correct) >= 14 && stripos($stem, rtrim($correct, ' .')) !== false) continue;

        // shuffle so the correct answer isn't always in the same slot
        $order = [0, 1, 2, 3]; shuffle($order);
        $new = []; $newCorrect = 'A';
        foreach ($order as $pos => $from) { $new[] = $opts[$from]; if ($from === $correctIdx) $newCorrect = $letters[$pos]; }

        $expl = trim((string)($q['explanation'] ?? ''));
        $expl = trim(preg_replace('/\b(option|answer|choice)\s+\(?[A-D]\)?\b[:,.]?\s*/i', '', $expl));   // letters no longer match after shuffle
        $expl = qe_sentence_case($expl);
        if (!preg_match('/[?]["”]?\s*$/u', $stem) && !preg_match('/[:.]["”]?\s*$/u', $stem) && !preg_match('/_{2,}/', $stem)) $stem = rtrim($stem) . '?';

        // reject near-duplicates of a question we already kept
        $dup = false;
        foreach ($out as $kept) if (qe_similarity($kept['question_text'], $stem) > 0.8) { $dup = true; break; }
        if ($dup) continue;

        $out[] = ['question_text' => $stem, 'option_a' => $new[0], 'option_b' => $new[1], 'option_c' => $new[2], 'option_d' => $new[3],
                  'correct_option' => $newCorrect, 'explanation' => $expl];
        if (count($out) >= $limit) break;
    }
    return $out;
}

/** Pull the question array out of whatever the model returned. */
function qe_parse_ai_json(string $text): ?array {
    $text = trim($text);
    $text = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $text);
    $try = [$text];
    if (preg_match('/\[.*\]/s', $text, $m)) $try[] = $m[0];
    if (preg_match('/\{.*\}/s', $text, $m)) $try[] = $m[0];
    foreach ($try as $t) {
        $d = json_decode($t, true);
        if (is_array($d)) {
            if (isset($d['questions']) && is_array($d['questions'])) return $d['questions'];
            if (isset($d[0]) && is_array($d[0])) return $d;
        }
    }
    return null;
}

function qe_ai_prompt(string $notes, string $topic, string $context, string $type, string $difficulty, int $num): string {
    $typeRule = match ($type) {
        'practical' => "PRACTICAL APPLICATION: each question describes a short realistic situation (1–2 sentences) and asks what the student should do, choose or conclude using ideas from the notes.",
        'aptitude'  => "GENERAL APTITUDE: each question tests reasoning — comparing two ideas from the notes, spotting which statement follows logically, or choosing the odd one out.",
        default     => "CORE THEORY: each question tests understanding of a definition, purpose, component or difference taught in the notes.",
    };
    $diffRule = match (strtolower($difficulty)) {
        'beginner' => "BEGINNER: one clear fact per question, plain words, distractors that are clearly different from the answer.",
        'advanced' => "ADVANCED: needs understanding, not recall; distractors are close, plausible mix-ups of related ideas from the notes.",
        default    => "INTERMEDIATE: needs understanding of the idea; distractors are plausible but wrong.",
    };
    $hasNotes = trim($notes) !== '';
    $source = $hasNotes
        ? "Use ONLY the lecture notes below. Do not use outside facts.\n\nLECTURE NOTES (headings start with ##):\n\"\"\"\n$notes\n\"\"\"\n"
        : "No notes were provided. Use standard, well-established knowledge of the course unit \"$context\".\n";
    return "You write high-quality multiple-choice quiz questions for university students.\n\n"
        . "Unit: $context\nTopic: $topic\nNumber of questions: $num\n\n$source\n"
        . "QUESTION TYPE — $typeRule\nDIFFICULTY — $diffRule\n\n"
        . "STRICT QUALITY RULES\n"
        . "1. Every question must be complete and understandable on its own. Never write \"according to the notes/text/slide\" or refer to slides, pages or figures.\n"
        . "2. Ask one clear thing. Use a full sentence ending in a question mark (or a statement with a blank for completion questions).\n"
        . "3. Exactly 4 options. Exactly 1 is correct and it must be unambiguously correct according to the notes.\n"
        . "4. The 3 wrong options must be plausible, grammatically parallel to the correct one, and similar in length. No joke options. Never use \"All of the above\" or \"None of the above\".\n"
        . "5. Cover DIFFERENT parts of the material; no two questions may test the same fact.\n"
        . "6. Do not mention option letters anywhere in the explanation. The explanation is one short sentence saying why the answer is right.\n"
        . "7. Vary which option is correct.\n\n"
        . "OUTPUT: respond with ONLY a JSON array, no markdown, no commentary:\n"
        . "[{\"question_text\":\"...\",\"option_a\":\"...\",\"option_b\":\"...\",\"option_c\":\"...\",\"option_d\":\"...\",\"correct_option\":\"A\",\"explanation\":\"...\"}]";
}
