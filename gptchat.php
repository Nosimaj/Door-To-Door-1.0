<?php
// ADDED: Support JSON POST bodies safely (no behavior change for form posts)
$rawInput = file_get_contents("php://input");
$jsonInput = json_decode($rawInput, true);
if (is_array($jsonInput)) {
    $_POST = array_merge($_POST, $jsonInput);
}

// --- TRIAGE mode prompt (convert-first) ---
$triagePrompt = "You are Lloyd, the sardonic underground New England bike mechanic for Door to Door Bike Repair. "
  . "Your primary goal is to convert the customer to a booking, not to teach them repairs. "
  . "Be concise (3–4 sentences), dry, a little smug, but still genuinely helpful. "
  . "If the issue is clear from their note/photos, immediately show the booking button as HTML: "
  . "👉 <a id='bookNowBtn' class='btn btn-warning'>Book your repair</a> "
  . "Then give a quick estimated range and what’s included. "
  . "If info is missing, ask ONE short clarifying question and then offer the booking link. "
  . "Do NOT show step-by-step DIY tools or instructions in TRIAGE mode. "
  . "LOCAL VIBE: One subtle Boston/New England ‘insider’ nod per reply (e.g., getting Storrowed, Allston Christmas curb-dive season, the T doing the T thing), never touristy, never forced.";

/**
 * Door To Door Repair — Lloyd vNext (crash-safe)
 * [PATCHED 2025-10-09 v2 + DIY helpers 2025-10-15 + TRIAGE no-DIY + DIY quote-at-end + mode/suppress overrides]
 *
 * This version patched to:
 * - stop DIY from showing estimates/CTA
 * - clean mode chooser (no unconditional DIY)
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// Allow cross-origin requests so the chat can be auto-posted from different domains
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
// Preflight response for OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit;
}

session_start();
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

$debugPath = __DIR__ . "/debug.log";

/** Crash-safe: turn fatals/warnings into JSON so frontend never sees HTML */
set_exception_handler(function($e) use ($debugPath) {
  file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] EXCEPTION: ".$e->getMessage()."\n".$e->getTraceAsString()."\n", FILE_APPEND);
  http_response_code(500);
  echo json_encode(["error"=>"Server exception","detail"=>$e->getMessage()]);
  exit;
});
set_error_handler(function($severity,$message,$file,$line) use ($debugPath){
  throw new ErrorException($message, 0, $severity, $file, $line);
});
register_shutdown_function(function() use ($debugPath){
  $err = error_get_last();
  if ($err && in_array($err['type'], [E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR])) {
    file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] FATAL: ".$err['message']." @ ".$err['file'].":".$err['line']."\n", FILE_APPEND);
    if (!headers_sent()) http_response_code(500);
    echo json_encode(["error"=>"Server fatal","detail"=>$err['message']]);
  }
});

require_once __DIR__ . '/config.php'; // safer include

// Resolve API key from constant or env
$apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : getenv('OPENAI_API_KEY');

file_put_contents($debugPath, "[" . date('Y-m-d H:i:s') . "] === New GPTCHAT Request ===\n", FILE_APPEND);

// ---------- Helpers ----------
function sanitizeForId($str) { return preg_replace('/[^a-zA-Z0-9]/', '', $str); }
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function is_valid_email($e) { return is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL); }
function is_meaningful_name($n) {
  if (!is_string($n)) return false;
  $t = trim($n);
  if (mb_strlen($t, 'UTF-8') < 2) return false;
  $placeholders = ['guest','unknown','anon','anonymous'];
  return !in_array(strtolower($t), $placeholders, true);
}

// ---------- Renderer ----------
function render_lloyd_json(array $j): string {
  $html = '';

  if (!empty($j['intro_banter'])) {
    $html .= "<div style='margin-bottom:6px'><em>" . h($j['intro_banter']) . "</em></div>";
  }

  if (!empty($j['dialogue_paragraphs']) && is_array($j['dialogue_paragraphs'])) {
    foreach ($j['dialogue_paragraphs'] as $p) {
      $p = trim($p);
      if ($p !== '') $html .= "<p>" . h($p) . "</p>";
    }
  }

  if (!empty($j['recommended_services']) && is_array($j['recommended_services'])) {
    $html .= "<div><strong>Estimate</strong></div><ul>";
    $totalLow = 0; $totalHigh = 0; $hasTotals = true;
    foreach ($j['recommended_services'] as $svc) {
      $name = h($svc['name'] ?? '');
      $low  = isset($svc['low']) ? floatval($svc['low']) : null;
      $high = isset($svc['high']) ? floatval($svc['high']) : null;
      if ($low === null || $high === null) $hasTotals = false; else { $totalLow += $low; $totalHigh += $high; }
      $notes = h($svc['notes'] ?? '');
      $range = ($low !== null && $high !== null)
        ? ('$' . rtrim(rtrim(number_format($low,2,'.',''), '0'), '.') . '–$' . rtrim(rtrim(number_format($high,2,'.',''), '0'), '.'))
        : '';
      $html .= "<li><strong>{$name}</strong>" . ($range ? " {$range}" : "") . ($notes ? " — {$notes}" : "") . "</li>";
    }
    $html .= "</ul>";
    if ($hasTotals && $totalLow > 0 && $totalHigh >= $totalLow) {
      $sum = '$' . rtrim(rtrim(number_format($totalLow,2,'.',''), '0'), '.') . '–$' . rtrim(rtrim(number_format($totalHigh,2,'.',''), '0'), '.');
      $html .= "<div><em>Ballpark total:</em> {$sum}</div>";
    }
  }

  if (!empty($j['time_estimate']) && is_array($j['time_estimate'])) {
    $t = $j['time_estimate'];
    $lo = isset($t['low_minutes']) ? intval($t['low_minutes']) : null;
    $hi = isset($t['high_minutes']) ? intval($t['high_minutes']) : null;
    $note = h($t['note'] ?? '');
    if ($lo !== null && $hi !== null) {
      $renderRange = function($m) {
        if ($m < 90) return $m . " min";
        $hours = floor($m / 60);
        $mins = $m % 60;
        return $hours . " hr" . ($hours>1?"s":"") . ($mins?(" " . $mins . " min"):"");
      };
      $html .= "<div><em>Rough time:</em> " . $renderRange($lo) . " – " . $renderRange($hi) . ($note ? " — " . $note : "") . "</div>";
    }
  }

  if (!empty($j['potential_parts']) && is_array($j['potential_parts'])) {
    $html .= "<div><strong>Potential parts</strong></div><ul>";
    foreach ($j['potential_parts'] as $p) {
      $name  = h($p['name'] ?? '');
      $type  = h($p['type'] ?? '');
      $notes = h($p['notes'] ?? '');
      $tag   = ($type === 'customer_ok') ? ' (Customer-supplied OK)' :
               (($type === 'shop_stock') ? ' (Stock available)' : '');
      $html .= "<li>{$name}{$tag}" . ($notes ? " — {$notes}" : "") . "</li>";
    }
    $html .= "</ul>";
  }

  // DIY helper blocks (printed only if provided)
  if (!empty($j['diy_tools']) && is_array($j['diy_tools'])) {
    $html .= "<div><strong>Tools you’ll need</strong></div><ul>";
    foreach ($j['diy_tools'] as $t) {
      $t = trim((string)$t);
      if ($t !== '') $html .= "<li>" . h($t) . "</li>";
    }
    $html .= "</ul>";
  }

  if (!empty($j['diy_steps']) && is_array($j['diy_steps'])) {
    $html .= "<div><strong>Steps</strong></div><ol>";
    foreach ($j['diy_steps'] as $s) {
      $s = trim((string)$s);
      if ($s !== '') $html .= "<li>" . h($s) . "</li>";
    }
    $html .= "</ol>";
  }

  if (!empty($j['diy_tips']) && is_array($j['diy_tips'])) {
    $html .= "<div><strong>Pro tips</strong></div><ul>";
    foreach ($j['diy_tips'] as $tip) {
      $tip = trim((string)$tip);
      if ($tip !== '') $html .= "<li>" . h($tip) . "</li>";
    }
    $html .= "</ul>";
  }

  if (!empty($j['followup_question'])) {
    $html .= "<p><em>" . h($j['followup_question']) . "</em></p>";
  }

  if (!empty($j['disclaimer'])) {
    $html .= "<div style='margin-top:6px;font-size:0.95em;color:#555'>" . h($j['disclaimer']) . "</div>";
  }

  return $html ?: '';
}

// ---------- Input parsing (JSON or form) ----------
$rawBody = file_get_contents("php://input");
file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] INPUT RAW: $rawBody\n", FILE_APPEND);
$input = json_decode($rawBody, true);

// Fallback to form POST if JSON missing
if (!is_array($input)) {
  $input = [
    "message"   => $_POST["message"]   ?? null,
    "name"      => $_POST["name"]      ?? null,
    "email"     => $_POST["email"]     ?? null,
    "images"    => $_POST["images"]    ?? null,
    "imagefile" => $_POST["imagefile"] ?? null,
    "chat_id"   => $_POST["chat_id"]   ?? null,
  ];
}

$userMessage    = trim($input["message"] ?? "");
$userName       = trim($input["name"] ?? "");
$userEmail      = trim($input["email"] ?? "");
$userImagesPath = trim($input["images"] ?? "");
$userImageFiles = trim($input["imagefile"] ?? "");

// ---------- Guards ----------
if (!$apiKey) {
  file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] ERROR: Missing OPENAI_API_KEY\n", FILE_APPEND);
  http_response_code(500);
  echo json_encode(["error" => "Missing OPENAI_API_KEY"]);
  exit;
}
if (!$userMessage) {
  file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] ERROR: Empty message\n", FILE_APPEND);
  http_response_code(400);
  echo json_encode(["error" => "Empty message"]);
  exit;
}

// ---------- Mode selection + chatId (PATCHED) ----------
$hasValidEmail = is_valid_email($userEmail);
$hasGoodName   = is_meaningful_name($userName);

// Optional overrides from URL/payload
$modeOverride = isset($_GET['mode']) ? strtoupper(trim($_GET['mode'])) : null;
if (!$modeOverride && isset($input['mode']) && is_string($input['mode'])) {
  $modeOverride = strtoupper(trim($input['mode']));
}
$triageOverride = (isset($_GET['triage']) && $_GET['triage'] == '1')
  || (!empty($input['triage']))
  || ($modeOverride === 'TRIAGE');
if ($triageOverride) {
  $modeOverride = 'TRIAGE';
}
$noDiyQuery   = (isset($_GET['no_diy']) && $_GET['no_diy'] == '1');            // URL switch
$noDiyPayload = (!empty($input['suppress_diy']));                               // Payload switch
$suppressDIY  = $noDiyQuery || $noDiyPayload || ($modeOverride === 'TRIAGE');

// ---------- Decide mode (patched) ----------
if ($modeOverride === 'TRIAGE') {
  // TRIAGE if forced
  $modeBlock = "MODE: TRIAGE (explicit override). Use images+text to estimate. If unclear, ask one crisp follow-up. DO NOT include diy_tools, diy_steps, or diy_tips in TRIAGE.";
  $chosenMode = 'TRIAGE';
} elseif ($modeOverride === 'DIY') {
  // Explicit DIY
  $modeBlock = "MODE: DIY (public/iframe). Same structure. Give diagnosis + DIY guidance only. DO NOT provide dollar estimates or booking language. If user insists on price, tell them a human will confirm.";
  $chosenMode = 'DIY';
} else {
  // Default for anonymous iframe = DIY, still no estimates
  $modeBlock = "MODE: DIY (public/iframe). Same structure. Give diagnosis + DIY guidance only. DO NOT provide dollar estimates or booking language. If user insists on price, tell them a human will confirm.";
  $chosenMode = 'DIY';
}
file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] MODE: $chosenMode\n", FILE_APPEND);

// ---------- Price sheet ----------
$pricingBlock = <<<'PRICES'
PRICE_SHEET_V1 (do not invent prices):
- On-site Flat Repair: 75
- On-site Drivetrain/Brake Adjustment (per system): 50
- On-site Safety Inspection: 75
- Flat Inner Tube Repair standalone: 50-75 (+tube)
- Tubeless Repair: 75-150
- Tubeless Install: 75-150
- Brake Adjustment (per brake): 25-55
- Disc Pad & Rotor Cleaning (per brake): 25-35
- Brake Cable Replacement (labor): 35-50
- Hydraulic Brake Bleed (per brake, fluid+labor): 60-80
- Chain Replacement (labor): 35-80
- Wheel Truing (per wheel): 35-50
- Hub Bearing Adjustment (per wheel): 35-70
- Quick Tune: 100-150
- Full Tune: 150-175
- Annual Overhaul: 200-300
- Notes: Free pickup/delivery threshold is $75 
- Spokes cost $3.5 each + $25-$75 in labor
PRICES;

// ---------- LIVE MEMBERSHIP SCRAPER (runtime; source-of-truth = your pages) ----------
function fetch_membership_page($url) {
  $ctx = stream_context_create([
    "http" => [
      "method" => "GET",
      "header" => implode("\r\n", [
        "User-Agent: D2D-LloydBot/1.0",
        "Accept: text/html"
      ]),
      "timeout" => 12
    ]
  ]);
  return @file_get_contents($url, false, $ctx);
}

function extract_membership_sections($html) {
  if (!$html || strlen($html) < 200) return "";

  libxml_use_internal_errors(true);
  $dom = new DOMDocument();
  $dom->loadHTML($html);
  $xp = new DOMXPath($dom);

  $chunks = [];

  // Heuristic selectors (tighten later if you add ids/classes)
  $queries = [
    "//*[self::h1 or self::h2 or self::h3][contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'membership')]",
    "//*[contains(translate(@id, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'membership')]",
    "//*[contains(translate(@class, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'membership')]",
    "//*[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'bunch care')]",
    "//*[contains(translate(., 'ABCDEFGHIJKLMNOPQRSTUVWXYZ','abcdefghijklmnopqrstuvwxyz'),'plan')]"
  ];

  foreach ($queries as $q) {
    foreach ($xp->query($q) as $node) {
      $text = trim(preg_replace("/\s+/", " ", $node->textContent));
      if (strlen($text) > 50) $chunks[] = $text;
    }
  }

  if (empty($chunks)) {
    $fallback = trim(preg_replace("/\s+/", " ", strip_tags($html)));
    return substr($fallback, 0, 1200);
  }

  $chunks = array_values(array_unique($chunks));
  $joined = implode("\n\n", $chunks);
  return substr($joined, 0, 2500);
}

$pricingHtml = fetch_membership_page("https://doortodoorrepair.com/pricing.php");
$bunchHtml   = fetch_membership_page("https://doortodoorrepair.com/bunch-care.php");

$pricingText = extract_membership_sections($pricingHtml);
$bunchText   = extract_membership_sections($bunchHtml);

$membershipBlock =
  "MEMBERSHIPS_LIVE_RUNTIME (authoritative; do not invent):\n"
  . ($pricingText ? "=== PRICING PAGE ===\n$pricingText\n\n" : "Pricing page unavailable; do not mention specific plan names/prices unless user asks and you say a human will confirm.\n\n")
  . ($bunchText   ? "=== BUNCH CARE PAGE ===\n$bunchText\n"   : "Bunch Care page unavailable; same rule.\n");

// ---------- Boston Framebuilder Lore (loose integration) ----------
$bostonBuildersLore = <<<LORE
Lloyd treats New England framebuilder lore like other people treat dive-bar bands: sacred, obscure, and not for explaining to tourists.  
If the convo even *sniffs* of craftsmanship, materials, weird bike history, or pride in a well-loved machine, Lloyd might deadpan a local builder name-drop — casual, not a lecture.

LEGENDS & LINEAGE:
- Fat City Cycles / Fat Chance – Chris Chance’s Somerville cult.
- Merlin Metalworks – Cambridge titanium pioneers.
- Independent Fabrication (IF) – Lynn crew of Fat City exiles.
- Seven Cycles – Rob Vandermark’s Watertown precision temple.
- Firefly Bicycles – ex-IF/Seven artists.
- Parlee Cycles – Beverly carbon dreamers.
- A.N.T. Bicycles – Mike Flanigan’s commuter masterpieces.
- Geekhouse – Marty Walsh’s loud-paint Boston punk frames.
- Royal H, Icarus, Zanconato, Hot Tubes, Peter Mooney, Igleheart, Circle A, Chapman, Maietta, Ted Wojcik – New England handmade backbone.
- Historic roots: Witcomb USA → Sachs, Weigle, Serotta.

LORE;

// ---------- Persona / rules ----------
$coreRules = <<<SYS
You are Lloyd, a bike repair AI specialist for Door To Door Repair in Boston. Lloyd uses they/them pronouns.

TONE & PERSONALITY:
- KIND BUT NOT ALWAYS NICE. Dry, fast, New England cadence.
- Knowledgeable, slightly sarcastic, and genuinely care about bikes.
- Never touristy, never forced, accidentally natural tone.

LOCAL FLAVOR (STRICT):
- Include exactly ONE subtle Boston/New England insider nod per reply.
  Examples: getting Storrowed, Allston Christmas curb-dive season, the T being the T, Somerville winter potholes, Newton hills, Cape shoulder-season emptiness.
- Make it feel accidental, not a checklist. No tourist voice, no “come visit Boston” energy.

STRUCTURE:
- Open with a one-sentence intro_banter (third person) about the mid-hipster task they were interrupted from
  (e.g., dialing a finicky espresso shot, arguing about 90s ska, tuning a crusty fixed-gear, reading obscure framebuilder forums). < 30 words.
- Then address the user in 2–3 SHORT dialogue_paragraphs (<=45 words each). Lloyd speaks first-person here.
  Each paragraph gets ONE crisp, witty observation max. Roast lightly, then help.
- Keep it conversational. User mostly wants: what might be wrong, roughly what it costs, and how long it takes.

CONSISTENCY:
- Don’t escalate unless new evidence appears.
- If a service is declined, don’t re-recommend it unless reopened.

GUARDRAILS:
- Reference PRICE_SHEET_V1 for accurate labor costs. Never invent prices.
- Infer misspelled brands and state you interpreted them.
- No motorcycle advice. Don’t suggest patching tubes.
- Ask at most ONE targeted follow-up only if it materially improves the estimate.
- If safety is questionable, clearly mark “not safe to ride.”

WHEEL-TRUING DISCIPLINE:
- Recommend Wheel Truing only with visible wobble, broken/loose spokes, brake rub, or explicit “wheel wobbles/rubs.”
  Otherwise stick to tire/tube for flats.

IMAGE USE (when present):
1) Identify components & visible damage with dry local shade.
2) Infer likely cause with a natural New England aside if it fits.
3) Estimate labor strictly from PRICE_SHEET_V1.
4) Call out uncertainty with a deadpan local vibe (short).

PARTS SCOPE:
- Include a short "potential_parts" list (0–3 items). Tag each: "customer_ok" or "shop_stock".

OUTPUT ORDER (strict):
- intro_banter (third person, interrupted)
- dialogue_paragraphs (array of 2–3 short paragraphs; Lloyd first-person; no headers)
- recommended_services (1–3 items with price ranges from PRICE_SHEET_V1)
- time_estimate (minutes low/high + one-line note; render under Estimate as “Rough time:”)
- potential_parts (0–3 items max)
- followup_question (optional, one line)

STYLE PRESSURE:
- No pep-talk voice. Be helpful like a grumpy friend who actually knows their stuff.
- Concise but colorful sentences. One sharp joke per paragraph, tops.
SYS;

$jsonSchemaHint = <<<JHINT
Return ONLY valid JSON matching:
{
  "intro_banter": string,
  "dialogue_paragraphs": string[],
  "recommended_services": [
    {"name": string, "low": number, "high": number, "notes": string}
  ],
  "time_estimate": {
    "low_minutes": number,
    "high_minutes": number,
    "note": string
  },
  "potential_parts": [
    {"name": string, "type": "customer_ok"|"shop_stock", "notes": string}
  ],
  "diy_tools": string[] | null,
  "diy_steps": string[] | null,
  "diy_tips": string[] | null,
  "followup_question": string|null,
  "confidence": "low"|"med"|"high",
  "disclaimer": string
}
JHINT;

// ---------- Session / state ----------
$sessionDir = __DIR__ . "/sessions";
if (!file_exists($sessionDir)) {
  if (!mkdir($sessionDir, 0775, true)) throw new Exception("Cannot create sessions dir");
}
if (!is_writable($sessionDir)) throw new Exception("sessions directory not writable");

// Build chatId: identified users vs DIY session
if ($hasGoodName && $hasValidEmail) {
  $chatId = sanitizeForId($userName) . '_' . sanitizeForId($userEmail);
} else {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $chatId = 'DIY_' . session_id();
}

$sessionFile = "$sessionDir/$chatId.json";
if (!file_exists($sessionFile)) file_put_contents($sessionFile, json_encode([]));
$chatHistory = json_decode(file_get_contents($sessionFile), true);
if (!is_array($chatHistory)) $chatHistory = [];

$stateFile = "$sessionDir/$chatId.state.json";
if (!file_exists($stateFile)) file_put_contents($stateFile, json_encode(["rejected_services"=>[]]));
$state = json_decode(file_get_contents($stateFile), true) ?: ["rejected_services"=>[]];

// Reset if different email (new session) — only for identified user
if ($hasGoodName && $hasValidEmail) {
  $firstMessage = $chatHistory[0]['content'] ?? '';
  if (stripos($firstMessage, $userEmail) === false) {
    $chatHistory = [];
    $state = ["rejected_services"=>[]];
    file_put_contents($stateFile, json_encode($state));
    file_put_contents($debugPath, "[" . date('Y-m-d H:i:s') . "] Session reset for new email: $userEmail\n", FILE_APPEND);
  }
}

// ---------- Images ----------
$fullUrls = [];
if (!empty($userImagesPath) && !empty($userImageFiles)) {
  $filesArray = array_filter(array_map('trim', explode(',', $userImageFiles)));
  if (!empty($filesArray)) {
    $fullUrls = array_map(function($f) use ($userImagesPath) {
      return "https://doortodoorrepair.com" . $userImagesPath . $f;
    }, $filesArray);
  }
}

// ---------- Logs ----------
file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] INCOMING name: $userName | email: $userEmail | chatId: $chatId | imagesPath: $userImagesPath | files: $userImageFiles | modeOverride: ".($modeOverride ?: '(none)')." | suppressDIY: ".($suppressDIY?'true':'false')."\n", FILE_APPEND);

// ---------- Duplicate guard ----------
$isTriageSeed = stripos($userMessage, 'Customer triage uploaded by') === 0;
$isFirstTurn  = empty($chatHistory);

$lastUserKey = null;
for ($i = count($chatHistory) - 1; $i >= 0; $i--) {
  if (($chatHistory[$i]['role'] ?? '') === 'user') { $lastUserKey = trim($chatHistory[$i]['content'] ?? ''); break; }
}
$currentKey = $userMessage . '|' . implode(',', $fullUrls);

// Mode-aware triage seed repeat skip
if ($chosenMode === 'TRIAGE' && $isTriageSeed && !$isFirstTurn) {
  file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] SKIP: triage seed repeated (TRIAGE mode)\n", FILE_APPEND);
  $lastAssistant = null;
  for ($i = count($chatHistory) - 1; $i >= 0; $i--) {
    if (($chatHistory[$i]['role'] ?? '') === 'assistant') { $lastAssistant = $chatHistory[$i]['content']; break; }
  }
  echo json_encode(["choices" => [["message" => ["content" => $lastAssistant ?? ""]]]]);
  exit;
}

if ($lastUserKey !== null && $lastUserKey === $currentKey) {
  file_put_contents($debugPath, "[".date('Y-m-d H:i:s')."] SKIP: duplicate user message (with images)\n", FILE_APPEND);
  $lastAssistant = null;
  for ($i = count($chatHistory) - 1; $i >= 0; $i--) {
    if (($chatHistory[$i]['role'] ?? '') === 'assistant') { $lastAssistant = $chatHistory[$i]['content']; break; }
  }
  echo json_encode(["choices" => [["message" => ["content" => $lastAssistant ?? ""]]]]);
  exit;
}

// Update rejection state from negations
$rej = $state["rejected_services"];
$neg = strtolower($userMessage);
$rejectionMap = [
  'Wheel Truing' => ['wheel fixing','wheel true','truing','true the wheel','wheel straightening'],
  'On-site Safety Inspection' => ['no safety inspection','don’t need safety inspection','dont need inspection'],
];
foreach ($rejectionMap as $svc => $phrases) {
  foreach ($phrases as $p) {
    if (strpos($neg, $p) !== false || preg_match('/\b(no|don\'t|do not|not)\s+(need|want).*\b(wheel|truing|true|straighten)\b/i', $neg)) {
      if (!in_array($svc, $rej, true)) $rej[] = $svc;
    }
  }
}
$state["rejected_services"] = array_values(array_unique($rej));
file_put_contents($stateFile, json_encode($state));

// ---------- Build OpenAI messages ----------
$messages = [];
$messages[] = ["role" => "system", "content" => $coreRules];
$messages[] = ["role" => "system", "content" => $pricingBlock];
$messages[] = ["role" => "system", "content" => $membershipBlock]; // LIVE memberships at runtime
$messages[] = ["role" => "system", "content" => $modeBlock];

$rejectedNote = !empty($state["rejected_services"])
  ? "USER_REJECTIONS: The user has declined these services in this chat. Do NOT recommend them again unless explicitly reopened: " . implode(', ', $state["rejected_services"]) . "."
  : "USER_REJECTIONS: (none)";
$messages[] = ["role" => "system", "content" => $rejectedNote];

if (preg_match('/\bflat\b/i', $userMessage) && !preg_match('/\b(wobble|rubbing|rubs|buckl|bent|true|truing)\b/i', $userMessage)) {
  $messages[] = ["role"=>"system","content"=>"NUDGE: User reports a flat; unless clear wobble/rub evidence, prefer tube/tire diagnosis. Don’t recommend Wheel Truing."];
}
if (preg_match('/\b(hit by a car|crash|collision|got hit|was hit|accident)\b/i', $userMessage)) {
  $messages[] = ["role"=>"system","content"=>"CRASH_CONTEXT: Acknowledge briefly with empathy, lead with safety, avoid jokes about injury."];
}

// Updated local flavor rule: exactly one subtle insider nod per reply
$messages[] = ["role"=>"system","content"=>"LOCAL FLAVOR: Include exactly ONE subtle New England insider nod per reply (e.g., getting Storrowed, Allston Christmas curb-dive season, the T being the T, Somerville potholes). No tourist energy, no corporate clichés, no additional local shoutouts. Natural, not forced."];

// Membership behavior nudge
$messages[] = ["role"=>"system","content"=>"MEMBERSHIP USE: If the live KB indicates a plan clearly covers the user’s likely repair, mention ONLY the single best-fitting plan in one sentence and what it covers. In TRIAGE, lightly upsell if it saves money; in DIY, only mention if user asks about value/pricing. Do not invent details."];

// User message + images
$userParts = [ ["type" => "text", "text" => $userMessage] ];
foreach ($fullUrls as $u) {
  $userParts[] = ["type" => "image_url", "image_url" => ["url" => $u]];
}
$messages[] = ["role" => "user", "content" => $userParts];

// Persist compact user key (keep last 10)
$chatHistory[] = ["role" => "user", "content" => $currentKey];
$chatHistory = array_slice($chatHistory, -10);
file_put_contents($sessionFile, json_encode($chatHistory, JSON_PRETTY_PRINT));

// JSON schema hint last
$messages[] = ["role" => "system", "content" => $jsonSchemaHint];

file_put_contents($debugPath, "[" . date('Y-m-d H:i:s') . "] FULL MESSAGE HISTORY:\n" . json_encode($messages, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

// ---------- OpenAI call ----------
$payload = [
  "model" => "gpt-4o",
  "messages" => $messages,
  "temperature" => 0.72,
  "presence_penalty" => 0.2,
  "frequency_penalty" => 0.15,
  "max_tokens" => 700,
  "response_format" => ["type" => "json_object"]
];

$ch = curl_init("https://api.openai.com/v1/chat/completions");
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_HTTPHEADER => [
    "Content-Type: application/json",
    "Authorization: Bearer $apiKey"
  ],
  CURLOPT_POSTFIELDS => json_encode($payload),
  CURLOPT_TIMEOUT => 25,
  CURLOPT_CONNECTTIMEOUT => 10,
  CURLOPT_ENCODING => '',
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
if ($response === false) {
  $err = curl_error($ch);
  file_put_contents($debugPath, "[" . date('Y-m-d H:i:s') . "] CURL ERROR: $err\n", FILE_APPEND);
  curl_close($ch);
  http_response_code(502);
  echo json_encode(["error" => "Upstream connection error", "detail" => $err]);
  exit;
}
curl_close($ch);
file_put_contents($debugPath, "[" . date('Y-m-d H:i:s') . "] HTTP $httpCode\nRESPONSE: $response\n", FILE_APPEND);

// ---------- Parse & render ----------
$data = json_decode($response, true);
if (!isset($data["choices"][0]["message"]["content"])) {
  $msg = $data["error"]["message"] ?? "Unknown upstream response";
  file_put_contents($debugPath, "[" . date('Y-m-d H:i:s') . "] OPENAI ERROR: $msg\n", FILE_APPEND);
  http_response_code(502);
  echo json_encode(["error" => "OpenAI API error", "detail" => $msg, "http" => $httpCode]);
  exit;
}

$raw = $data["choices"][0]["message"]["content"];
$decoded = json_decode($raw, true);

// Helper: render just the Estimate block
$renderEstimateOnly = function(array $j): string {
  $mini = [];
  if (!empty($j['recommended_services'])) $mini['recommended_services'] = $j['recommended_services'];
  if (!empty($j['time_estimate']))        $mini['time_estimate']        = $j['time_estimate'];
  return $mini ? render_lloyd_json($mini) : '';
};

if (is_array($decoded) && (isset($decoded['recommended_services']) || isset($decoded['dialogue_paragraphs']))) {
  // Filter previously rejected services
  if (!empty($state["rejected_services"]) && isset($decoded['recommended_services'])) {
    $decoded['recommended_services'] = array_values(array_filter(
      $decoded['recommended_services'],
      function($svc) use ($state) {
        $name = strtolower($svc['name'] ?? '');
        foreach ($state["rejected_services"] as $rej) {
          if ($name === strtolower($rej)) return false;
          if ($rej && strpos($name, strtolower($rej)) !== false) return false;
        }
        return true;
      }
    ));
  }

  // Flat fallback
  if (empty($decoded['recommended_services']) && preg_match('/\bflat\b/i', $userMessage)) {
    $decoded['recommended_services'] = [
      ["name"=>"Flat Inner Tube Repair standalone","low"=>50,"high"=>75,"notes"=>"Includes new tube if needed."]
    ];
  }

  // Persist potential parts (accumulate per chat)
  $partsFile = __DIR__ . "/sessions/$chatId.parts.json";
  if (isset($decoded['potential_parts']) && is_array($decoded['potential_parts'])) {
    $existing = file_exists($partsFile) ? json_decode(file_get_contents($partsFile), true) : [];
    if (!is_array($existing)) $existing = [];
    $merged = array_values(array_merge($existing, $decoded['potential_parts']));
    file_put_contents($partsFile, json_encode($merged, JSON_PRETTY_PRINT));
  }

  if ($chosenMode === 'DIY' && !$suppressDIY) {
    // DIY: show Lloyd, tools, steps, tips — NO estimates, NO time
    unset($decoded['recommended_services'], $decoded['time_estimate']);
    $renderedBody  = render_lloyd_json($decoded);
    $estimateBlock = '';
  } else {
    // TRIAGE or DIY-with-suppression
    if ($chosenMode === 'TRIAGE' && isset($decoded['recommended_services']) && is_array($decoded['recommended_services'])) {
        $isQuickFix = false;
        if (count($decoded['recommended_services']) === 1) {
            $svc0 = $decoded['recommended_services'][0];
            $name = strtolower($svc0['name'] ?? '');
            if (preg_match('/flat|tube|tire|puncture|adjust/i', $name)) {
                $isQuickFix = true;
            }
        }
        if (!$isQuickFix) {
            $decoded['time_estimate'] = 'Turnaround: 24–48 hours';
        }
    }

    // TRIAGE scrub: remove any DIY instructions that the model may have emitted
    if ($chosenMode === 'TRIAGE') {
        unset($decoded['diy_tools'], $decoded['diy_steps'], $decoded['diy_tips']);
        unset($decoded['tools'], $decoded['steps'], $decoded['tips'], $decoded['how_to'], $decoded['instructions']);
        if (isset($decoded['dialogue_paragraphs']) && is_array($decoded['dialogue_paragraphs'])) {
            $scrubbed = [];
            foreach ($decoded['dialogue_paragraphs'] as $para) {
                $s = is_string($para) ? $para : '';
                if (preg_match('/\bDIY\b/i', $s)) continue;
                if (preg_match('/\b(steps?|instructions?|how to|do this|do the following|tool(s)? required|you(?:\'|’)ll need)\b/i', $s)) continue;
                if (preg_match('/^\s*(?:\d+[\.\)]|[-*•])\s+/m', $s)) continue;
                $scrubbed[] = $s;
            }
            $decoded['dialogue_paragraphs'] = $scrubbed;
        }
    }

    $renderedBody = render_lloyd_json($decoded);
    $estimateBlock = '';
  }
  $hasServicesOrig = !empty($decoded['recommended_services']);
} else {
  // Text mode fallback
  $renderedBody = h($raw);
  $hasServicesOrig = false;
  $estimateBlock = '';
}

// ---------- Conditional CTA (TRIAGE only now) ----------
$queryString = isset($_SERVER['QUERY_STRING']) ? trim($_SERVER['QUERY_STRING']) : '';
$scheduleUrl = "https://doortodoorrepair.com/schedule.php" . ($queryString ? ("?" . $queryString) : "");
$cta = '<div style="margin:20px 0"><a href="' . h($scheduleUrl) . '" class="btn btn-primary btn-sm" id="bookNowBtn">Book Now</a></div>';

$noCtaQuery   = (isset($_GET['no_cta']) && $_GET['no_cta'] == '1');
$noCtaPayload = (isset($input['suppress_cta']) && $input['suppress_cta']);
$wantCta = ($chosenMode === 'TRIAGE') && $hasServicesOrig && !$noCtaQuery && !$noCtaPayload;

// Compose final output
if ($chosenMode === 'DIY' && !$suppressDIY) {
  // DIY: body only, no estimate, no CTA
  $finalOut = $renderedBody;
} else {
  // TRIAGE or DIY with suppression: estimate already inside $renderedBody; CTA after it
  $finalOut = $renderedBody . ($wantCta ? $cta : '');
}

// Persist assistant turn
$chatHistory[] = ["role" => "assistant", "content" => $finalOut];
file_put_contents($sessionFile, json_encode($chatHistory, JSON_PRETTY_PRINT));

// Reply
echo json_encode(["choices" => [["message" => ["content" => $finalOut]]]]);
