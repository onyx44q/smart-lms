<?php
include 'config.php';
checkRole('lecturer');

$lecturer_id = (int)$_SESSION['user_id'];

// ── Ensure DB columns exist ────────────────────────────────────────────
@mysqli_query($conn, "ALTER TABLE materials MODIFY COLUMN `type` ENUM('pdf','video','word') DEFAULT NULL");
@mysqli_query($conn, "ALTER TABLE materials ADD COLUMN IF NOT EXISTS unit_id INT(11) DEFAULT NULL AFTER course_id");

// ── DELETE a material ──────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    $del_id = (int)$_GET['delete_id'];
    $row = mysqli_fetch_assoc(mysqli_query($conn,
        "SELECT file_path FROM materials WHERE id=$del_id AND lecturer_id=$lecturer_id"
    ));
    if ($row && file_exists($row['file_path'])) unlink($row['file_path']);
    if ($row) mysqli_query($conn, "DELETE FROM materials WHERE id=$del_id");
    header("Location: upload_material.php?msg=deleted");
    exit();
}

// ── Fetch units this lecturer is assigned to ───────────────────────────
$my_units = [];
$ur = mysqli_query($conn,
    "SELECT cu.id, cu.title, cu.unit_code, c.title AS course_title, c.id AS course_id
     FROM course_units cu
     JOIN courses c ON c.id = cu.course_id
     WHERE cu.lecturer_id = $lecturer_id
     ORDER BY c.title ASC, cu.title ASC"
);
while ($u = mysqli_fetch_assoc($ur)) $my_units[] = $u;

// ── Fetch ALL materials uploaded by this lecturer, grouped by unit ─────
$all_materials = [];
if (!empty($my_units)) {
    $unit_ids = implode(',', array_column($my_units, 'id'));
    $mr = mysqli_query($conn,
        "SELECT m.*, cu.title AS unit_title, cu.unit_code, c.title AS course_title
         FROM materials m
         LEFT JOIN course_units cu ON cu.id = m.unit_id
         LEFT JOIN courses c ON c.id = m.course_id
         WHERE m.lecturer_id = $lecturer_id AND m.unit_id IN ($unit_ids)
         ORDER BY m.unit_id ASC, m.id DESC"
    );
    while ($m = mysqli_fetch_assoc($mr)) $all_materials[$m['unit_id']][] = $m;
}

// ── Type icon/colour helper ────────────────────────────────────────────
function typeStyle($type) {
    return match($type) {
        'pdf'   => ['icon'=>'fa-file-pdf',    'bg'=>'bg-red-100',  'text'=>'text-red-600',  'badge'=>'bg-red-50 text-red-600 border-red-200',   'label'=>'PDF'],
        'video' => ['icon'=>'fa-circle-play', 'bg'=>'bg-blue-100', 'text'=>'text-blue-600', 'badge'=>'bg-blue-50 text-blue-600 border-blue-200', 'label'=>'Video'],
        'word'  => ['icon'=>'fa-file-word',   'bg'=>'bg-sky-100',  'text'=>'text-sky-700',  'badge'=>'bg-sky-50 text-sky-700 border-sky-200',    'label'=>'Word'],
        default => ['icon'=>'fa-file',        'bg'=>'bg-slate-100','text'=>'text-slate-500','badge'=>'bg-slate-50 text-slate-500 border-slate-200','label'=>'File'],
    };
}

$name = $_SESSION['user_name'] ?? 'Lecturer';
$msg  = $_GET['msg'] ?? '';
$uploaded_unit = (int)($_GET['uploaded_unit'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Upload Resources | SmartLMS</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap');
body { font-family: 'Plus Jakarta Sans', sans-serif; }
.drop-zone.drag-over { border-color:#6366f1; background:#eef2ff; }
@keyframes fadeUp { from{opacity:0;transform:translateY(8px)} to{opacity:1;transform:translateY(0)} }
.fade-up { animation: fadeUp .3s ease both; }
.type-selected-pdf   { border-color:#ef4444 !important; background:#fef2f2 !important; }
.type-selected-video { border-color:#3b82f6 !important; background:#eff6ff !important; }
.type-selected-word  { border-color:#0ea5e9 !important; background:#f0f9ff !important; }
</style>
</head>
<body class="bg-slate-50 min-h-screen">

<!-- Navbar -->
<nav class="bg-slate-900 px-6 py-4 flex items-center justify-between sticky top-0 z-50 shadow-lg">
    <div class="flex items-center gap-4">
        <a href="lecturer_dashboard.php"
           class="w-8 h-8 flex items-center justify-center rounded-xl bg-slate-800 text-slate-400 hover:text-white hover:bg-slate-700 transition">
            <i class="fa-solid fa-arrow-left text-sm"></i>
        </a>
        <div class="flex items-center gap-2">
            <div class="w-7 h-7 bg-indigo-500 rounded-lg flex items-center justify-center">
                <i class="fa-solid fa-bolt text-white text-xs"></i>
            </div>
            <span class="text-white font-extrabold">Smart<span class="text-indigo-400">LMS</span></span>
        </div>
        <span class="text-slate-500 text-xs font-bold uppercase tracking-widest hidden sm:block">/ Upload Resources</span>
    </div>
    <div class="flex items-center gap-2">
        <span class="text-slate-400 text-xs font-bold hidden md:block"><?php echo htmlspecialchars($name); ?></span>
        <div class="w-8 h-8 rounded-full bg-gradient-to-tr from-indigo-500 to-purple-500 flex items-center justify-center text-white font-black text-sm">
            <?php echo strtoupper(substr($name,0,1)); ?>
        </div>
    </div>
</nav>

<div class="max-w-6xl mx-auto px-6 py-8">

    <!-- Flash message -->
    <?php if ($msg): ?>
    <div class="mb-6 flex items-center gap-3 px-5 py-3.5 rounded-2xl text-sm font-bold <?php echo $msg==='deleted' ? 'bg-red-50 text-red-600 border border-red-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200'; ?> fade-up">
        <i class="fa-solid <?php echo $msg==='deleted' ? 'fa-trash-can' : 'fa-circle-check'; ?>"></i>
        <?php echo $msg==='deleted' ? 'Resource deleted successfully.' : 'Resource uploaded successfully!'; ?>
    </div>
    <?php endif; ?>

    <div class="mb-8">
        <h1 class="text-2xl font-extrabold text-slate-900">Upload Unit Resources</h1>
        <p class="text-slate-500 text-sm mt-1">
            Select which unit to upload to — students registered for that unit will see the resource immediately.
        </p>
    </div>

    <?php if (empty($my_units)): ?>
    <!-- No units assigned -->
    <div class="bg-white rounded-[2rem] border border-dashed border-slate-200 p-20 text-center">
        <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-layer-group text-slate-200 text-3xl"></i>
        </div>
        <p class="text-slate-600 font-black">You have no units assigned.</p>
        <p class="text-slate-400 text-sm mt-1">Ask your admin to assign you to a unit before you can upload resources.</p>
    </div>

    <?php else: ?>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-7 items-start">

        <!-- ════════════════════════════════════
             LEFT — UPLOAD FORM
        ════════════════════════════════════ -->
        <div class="lg:col-span-2">
            <div class="bg-white rounded-[1.75rem] border border-slate-100 shadow-sm overflow-hidden sticky top-24">

                <!-- Form header -->
                <div class="px-6 py-4 bg-gradient-to-r from-indigo-600 to-purple-600">
                    <h2 class="text-white font-extrabold text-sm">
                        <i class="fa-solid fa-cloud-arrow-up mr-2"></i>Upload a Resource
                    </h2>
                    <p class="text-indigo-200 text-[10px] mt-0.5">Choose the unit, then add title, type and file.</p>
                </div>

                <form action="process_upload.php" method="POST" enctype="multipart/form-data"
                      id="uploadForm" class="p-6 space-y-5">

                    <!-- ① Unit selector -->
                    <div>
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-2">
                            ① Select Unit *
                        </label>
                        <select name="unit_id" id="unitSelect" required
                            class="w-full p-3.5 bg-slate-50 border-2 border-slate-200 rounded-2xl text-sm font-bold outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 transition-all appearance-none"
                            onchange="onUnitChange(this)">
                            <option value="">— Pick a unit —</option>
                            <?php
                            $prev_course = '';
                            foreach ($my_units as $mu):
                                if ($mu['course_title'] !== $prev_course):
                                    if ($prev_course !== '') echo '</optgroup>';
                                    echo '<optgroup label="' . htmlspecialchars($mu['course_title']) . '">';
                                    $prev_course = $mu['course_title'];
                                endif;
                            ?>
                            <option value="<?php echo $mu['id']; ?>"
                                data-course="<?php echo htmlspecialchars($mu['course_title']); ?>"
                                data-unit="<?php echo htmlspecialchars($mu['title']); ?>"
                                data-code="<?php echo htmlspecialchars($mu['unit_code'] ?? ''); ?>">
                                <?php echo htmlspecialchars($mu['title']); ?>
                                <?php if ($mu['unit_code']): ?>(<?php echo htmlspecialchars($mu['unit_code']); ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; if ($prev_course !== '') echo '</optgroup>'; ?>
                        </select>

                        <!-- Selected unit preview badge -->
                        <div id="unitBadge" class="hidden mt-2 px-3 py-2 bg-indigo-50 border border-indigo-100 rounded-xl flex items-center gap-2">
                            <i class="fa-solid fa-book text-indigo-500 text-xs"></i>
                            <div>
                                <p id="unitBadgeName" class="text-xs font-black text-indigo-800"></p>
                                <p id="unitBadgeCourse" class="text-[9px] text-indigo-400 font-bold"></p>
                            </div>
                        </div>
                    </div>

                    <!-- ② Resource title -->
                    <div>
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-2">
                            ② Resource Title *
                        </label>
                        <input type="text" name="title" required
                               placeholder="e.g. Week 1 Notes — Introduction to AI"
                               class="w-full p-3.5 bg-slate-50 border-2 border-slate-200 rounded-2xl text-sm font-semibold outline-none focus:ring-2 focus:ring-indigo-400 focus:border-indigo-400 transition-all">
                    </div>

                    <!-- ③ File type -->
                    <div>
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-2">
                            ③ File Type *
                        </label>
                        <div class="grid grid-cols-3 gap-2">
                            <!-- PDF -->
                            <label class="cursor-pointer" id="lbl-pdf">
                                <input type="radio" name="type" value="pdf" class="hidden type-radio">
                                <div class="flex flex-col items-center gap-2 p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:border-red-300 hover:bg-red-50 transition-all select-none">
                                    <i class="fa-solid fa-file-pdf text-3xl text-red-400"></i>
                                    <span class="text-[9px] font-black uppercase text-slate-600">PDF</span>
                                </div>
                            </label>
                            <!-- Video -->
                            <label class="cursor-pointer" id="lbl-video">
                                <input type="radio" name="type" value="video" class="hidden type-radio">
                                <div class="flex flex-col items-center gap-2 p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:border-blue-300 hover:bg-blue-50 transition-all select-none">
                                    <i class="fa-solid fa-circle-play text-3xl text-blue-400"></i>
                                    <span class="text-[9px] font-black uppercase text-slate-600">Video</span>
                                </div>
                            </label>
                            <!-- Word -->
                            <label class="cursor-pointer" id="lbl-word">
                                <input type="radio" name="type" value="word" class="hidden type-radio">
                                <div class="flex flex-col items-center gap-2 p-4 rounded-2xl border-2 border-slate-200 bg-slate-50 hover:border-sky-300 hover:bg-sky-50 transition-all select-none">
                                    <i class="fa-solid fa-file-word text-3xl text-sky-500"></i>
                                    <span class="text-[9px] font-black uppercase text-slate-600">Word Doc</span>
                                </div>
                            </label>
                        </div>
                    </div>

                    <!-- ④ File picker / drag-drop -->
                    <div>
                        <label class="text-[9px] font-black uppercase text-slate-400 tracking-widest block mb-2">
                            ④ Choose File *
                        </label>
                        <div class="drop-zone relative border-2 border-dashed border-slate-200 rounded-2xl transition-all cursor-pointer hover:border-indigo-300 hover:bg-indigo-50/20"
                             id="dropZone">
                            <input type="file" name="resource_file" required id="fileInput"
                                   accept=".pdf,.doc,.docx,.mp4,.mov,.avi,.webm"
                                   class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10">

                            <!-- Default state -->
                            <div id="dropDefault" class="flex flex-col items-center justify-center py-9 px-4 pointer-events-none">
                                <i class="fa-solid fa-cloud-arrow-up text-4xl text-slate-200 mb-2"></i>
                                <p class="text-sm font-bold text-slate-400">Click or drag your file here</p>
                                <p class="text-[9px] text-slate-300 mt-1">PDF · DOCX · MP4 · MOV · AVI</p>
                            </div>

                            <!-- File selected state -->
                            <div id="filePreview" class="hidden p-4">
                                <div class="flex items-center gap-3">
                                    <div id="prevIconWrap" class="w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 bg-slate-100">
                                        <i id="prevIcon" class="fa-solid fa-file text-2xl text-slate-400"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p id="prevName" class="text-sm font-black text-slate-800 truncate"></p>
                                        <p id="prevSize" class="text-[10px] text-slate-400 mt-0.5"></p>
                                        <span id="prevBadge" class="inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-lg border text-[8px] font-black uppercase bg-slate-50 text-slate-500 border-slate-200"></span>
                                    </div>
                                    <div class="w-8 h-8 bg-emerald-100 rounded-xl flex items-center justify-center flex-shrink-0">
                                        <i class="fa-solid fa-check text-emerald-600"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Submit -->
                    <button type="submit"
                        class="w-full py-4 bg-indigo-600 hover:bg-indigo-700 text-white rounded-2xl font-black text-xs uppercase tracking-widest shadow-lg shadow-indigo-100 transition-all active:scale-95 flex items-center justify-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i>
                        Publish to Unit
                    </button>
                </form>
            </div>
        </div>

        <!-- ════════════════════════════════════
             RIGHT — MATERIALS GROUPED BY UNIT
        ════════════════════════════════════ -->
        <div class="lg:col-span-3 space-y-6">

            <?php if (empty(array_filter($all_materials))): ?>
            <div class="bg-white rounded-[1.75rem] border border-dashed border-slate-200 p-16 text-center">
                <div class="w-16 h-16 bg-slate-50 rounded-2xl flex items-center justify-center mx-auto mb-4">
                    <i class="fa-solid fa-file-arrow-up text-slate-200 text-3xl"></i>
                </div>
                <p class="text-slate-500 font-black text-sm">No resources uploaded yet.</p>
                <p class="text-slate-400 text-xs mt-1">Upload a resource using the form — it will appear here grouped by unit.</p>
            </div>

            <?php else:
            // Loop each assigned unit and show its materials
            foreach ($my_units as $mu):
                $unit_mats = $all_materials[$mu['id']] ?? [];
                $counts    = ['pdf'=>0,'video'=>0,'word'=>0];
                foreach ($unit_mats as $m) { $t=$m['type']??'pdf'; if(isset($counts[$t])) $counts[$t]++; }
                $highlight = ($uploaded_unit === $mu['id']);
            ?>

            <!-- Unit Section -->
            <div class="bg-white rounded-[1.75rem] border <?php echo $highlight ? 'border-indigo-300 shadow-lg shadow-indigo-100' : 'border-slate-100 shadow-sm'; ?> overflow-hidden fade-up"
                 id="unit-section-<?php echo $mu['id']; ?>">

                <!-- Unit header -->
                <div class="px-6 py-4 bg-gradient-to-r <?php echo $highlight ? 'from-indigo-600 to-purple-600' : 'from-slate-50 to-slate-100'; ?> flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="w-9 h-9 rounded-xl <?php echo $highlight ? 'bg-white/20' : 'bg-indigo-100'; ?> flex items-center justify-center flex-shrink-0">
                            <i class="fa-solid fa-book <?php echo $highlight ? 'text-white' : 'text-indigo-600'; ?> text-sm"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="font-black <?php echo $highlight ? 'text-white' : 'text-slate-900'; ?> text-sm">
                                    <?php echo htmlspecialchars($mu['title']); ?>
                                </h3>
                                <?php if ($mu['unit_code']): ?>
                                <span class="text-[9px] font-black uppercase px-2 py-0.5 rounded-lg <?php echo $highlight ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-600 border border-blue-100'; ?>">
                                    <?php echo htmlspecialchars($mu['unit_code']); ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <p class="text-[9px] font-bold <?php echo $highlight ? 'text-indigo-200' : 'text-slate-400'; ?> mt-0.5">
                                <?php echo htmlspecialchars($mu['course_title']); ?>
                            </p>
                        </div>
                    </div>
                    <!-- Counts -->
                    <div class="flex items-center gap-2">
                        <?php if ($counts['pdf'] > 0): ?>
                        <span class="flex items-center gap-1 px-2 py-1 rounded-lg text-[9px] font-black uppercase <?php echo $highlight ? 'bg-white/20 text-white' : 'bg-red-50 text-red-600'; ?>">
                            <i class="fa-solid fa-file-pdf"></i><?php echo $counts['pdf']; ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($counts['video'] > 0): ?>
                        <span class="flex items-center gap-1 px-2 py-1 rounded-lg text-[9px] font-black uppercase <?php echo $highlight ? 'bg-white/20 text-white' : 'bg-blue-50 text-blue-600'; ?>">
                            <i class="fa-solid fa-circle-play"></i><?php echo $counts['video']; ?>
                        </span>
                        <?php endif; ?>
                        <?php if ($counts['word'] > 0): ?>
                        <span class="flex items-center gap-1 px-2 py-1 rounded-lg text-[9px] font-black uppercase <?php echo $highlight ? 'bg-white/20 text-white' : 'bg-sky-50 text-sky-600'; ?>">
                            <i class="fa-solid fa-file-word"></i><?php echo $counts['word']; ?>
                        </span>
                        <?php endif; ?>
                        <span class="text-[9px] font-bold <?php echo $highlight ? 'text-indigo-200' : 'text-slate-400'; ?>">
                            <?php echo count($unit_mats); ?> file(s)
                        </span>
                    </div>
                </div>

                <!-- Materials list -->
                <?php if (empty($unit_mats)): ?>
                <div class="px-6 py-8 text-center">
                    <p class="text-slate-400 text-xs italic">No resources uploaded for this unit yet.</p>
                    <p class="text-[9px] text-slate-300 mt-1">Select "<strong><?php echo htmlspecialchars($mu['title']); ?></strong>" in the form to upload here.</p>
                </div>
                <?php else: ?>
                <div class="divide-y divide-slate-50">
                <?php foreach ($unit_mats as $mat):
                    $ext  = strtolower(pathinfo($mat['file_path'] ?? '', PATHINFO_EXTENSION));
                    $mtyp = $mat['type'] ?? match($ext) {
                        'pdf'       => 'pdf',
                        'mp4','mov','avi','webm' => 'video',
                        'doc','docx'=> 'word',
                        default     => 'pdf'
                    };
                    $s = typeStyle($mtyp);
                    $isVid = $mtyp === 'video';
                    $isPdf = $mtyp === 'pdf';
                    $uid   = 'prev_' . $mat['id'];
                ?>
                <div class="material-row" data-type="<?php echo $mtyp; ?>">
                    <!-- Row -->
                    <div class="px-5 py-3.5 flex items-center justify-between hover:bg-slate-50/70 transition-colors">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <!-- Type icon -->
                            <div class="w-10 h-10 rounded-xl flex items-center justify-center flex-shrink-0 <?php echo $s['bg']; ?>">
                                <i class="fa-solid <?php echo $s['icon']; ?> text-lg <?php echo $s['text']; ?>"></i>
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-black text-slate-900 truncate"><?php echo htmlspecialchars($mat['title']); ?></p>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <span class="inline-flex items-center gap-1 border text-[8px] font-black uppercase px-2 py-0.5 rounded-md <?php echo $s['badge']; ?>">
                                        <i class="fa-solid <?php echo $s['icon']; ?> text-[8px]"></i><?php echo $s['label']; ?>
                                    </span>
                                    <span class="text-[9px] text-slate-300 font-bold">.<?php echo $ext; ?></span>
                                </div>
                            </div>
                        </div>
                        <!-- Actions -->
                        <div class="flex items-center gap-1.5 ml-3 flex-shrink-0">
                            <?php if ($isVid || $isPdf): ?>
                            <button onclick="togglePrev('<?php echo $uid; ?>')"
                                class="px-3 py-1.5 bg-slate-50 border border-slate-200 hover:bg-indigo-50 hover:border-indigo-200 hover:text-indigo-600 text-slate-500 rounded-xl text-[9px] font-black uppercase tracking-widest transition-all">
                                <i class="fa-solid <?php echo $isVid ? 'fa-play' : 'fa-eye'; ?> mr-1"></i><?php echo $isVid ? 'Play' : 'View'; ?>
                            </button>
                            <?php endif; ?>
                            <a href="<?php echo htmlspecialchars($mat['file_path']); ?>" target="_blank"
                               class="w-8 h-8 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-center text-slate-500 hover:bg-indigo-600 hover:text-white hover:border-indigo-600 transition-all"
                               title="Download / Open">
                                <i class="fa-solid fa-download text-xs"></i>
                            </a>
                            <a href="?delete_id=<?php echo $mat['id']; ?>"
                               onclick="return confirm('Permanently delete this resource?')"
                               class="w-8 h-8 rounded-xl bg-red-50 text-red-400 hover:bg-red-600 hover:text-white flex items-center justify-center transition-all"
                               title="Delete">
                                <i class="fa-solid fa-trash-can text-xs"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Expandable inline preview -->
                    <div id="<?php echo $uid; ?>" class="hidden border-t border-slate-100">
                        <?php if ($isVid): ?>
                        <div class="p-4 bg-black">
                            <video controls class="w-full max-h-64 rounded-xl" preload="metadata">
                                <source src="<?php echo htmlspecialchars($mat['file_path']); ?>"
                                        type="video/<?php echo $ext==='webm'?'webm':($ext==='mov'?'quicktime':'mp4'); ?>">
                                Your browser does not support video.
                            </video>
                        </div>
                        <?php elseif ($isPdf): ?>
                        <div class="p-4 bg-slate-50">
                            <iframe src="<?php echo htmlspecialchars($mat['file_path']); ?>"
                                    class="w-full h-72 rounded-xl border border-slate-200" title="PDF Preview"></iframe>
                            <a href="<?php echo htmlspecialchars($mat['file_path']); ?>" target="_blank"
                               class="mt-2 block text-center text-[9px] font-black uppercase text-indigo-600 hover:underline">
                                Open in new tab <i class="fa-solid fa-arrow-up-right-from-square ml-1"></i>
                            </a>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>

            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
// ── Unit selector → show badge ─────────────────────────────────────────
function onUnitChange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const badge = document.getElementById('unitBadge');
    if (!sel.value) { badge.classList.add('hidden'); return; }
    document.getElementById('unitBadgeName').textContent =
        opt.dataset.unit + (opt.dataset.code ? ' (' + opt.dataset.code + ')' : '');
    document.getElementById('unitBadgeCourse').textContent = opt.dataset.course;
    badge.classList.remove('hidden');
}

// ── Type radio buttons ─────────────────────────────────────────────────
const typeConf = {
    pdf:   { iconClass:'fa-file-pdf',    iconColor:'text-red-500',  wrapBg:'bg-red-100',  badge:'bg-red-50 text-red-600 border-red-200',   label:'PDF'  },
    video: { iconClass:'fa-circle-play', iconColor:'text-blue-500', wrapBg:'bg-blue-100', badge:'bg-blue-50 text-blue-600 border-blue-200', label:'Video'},
    word:  { iconClass:'fa-file-word',   iconColor:'text-sky-600',  wrapBg:'bg-sky-100',  badge:'bg-sky-50 text-sky-700 border-sky-200',    label:'Word' },
};
let selType = null;

document.querySelectorAll('.type-radio').forEach(radio => {
    radio.closest('label').addEventListener('click', function() {
        radio.checked = true;
        selType = radio.value;
        // Reset all labels
        document.querySelectorAll('.type-radio').forEach(r => {
            const div = r.closest('label').querySelector('div');
            div.classList.remove('type-selected-pdf','type-selected-video','type-selected-word');
            div.classList.add('border-slate-200','bg-slate-50');
        });
        // Highlight selected
        const div = this.querySelector('div');
        div.classList.remove('border-slate-200','bg-slate-50');
        div.classList.add('type-selected-' + selType);
        updateIcon();
    });
});

function updateIcon() {
    const c = typeConf[selType]; if (!c) return;
    const wrap  = document.getElementById('prevIconWrap');
    const icon  = document.getElementById('prevIcon');
    const badge = document.getElementById('prevBadge');
    if (wrap)  { wrap.className = 'w-12 h-12 rounded-xl flex items-center justify-center flex-shrink-0 ' + c.wrapBg; }
    if (icon)  { icon.className = 'fa-solid ' + c.iconClass + ' text-2xl ' + c.iconColor; }
    if (badge) { badge.className = 'inline-flex items-center gap-1 mt-1 px-2 py-0.5 rounded-lg border text-[8px] font-black uppercase ' + c.badge;
                 badge.innerHTML = '<i class="fa-solid ' + c.iconClass + ' text-[8px]"></i> ' + c.label; }
}

// ── File picker → preview + auto-detect type ───────────────────────────
function detectType(name) {
    const ext = name.split('.').pop().toLowerCase();
    if (ext === 'pdf') return 'pdf';
    if (['mp4','mov','avi','webm'].includes(ext)) return 'video';
    if (['doc','docx'].includes(ext)) return 'word';
    return null;
}
function fmtBytes(b) {
    if (b < 1024) return b + ' B';
    if (b < 1048576) return (b/1024).toFixed(1) + ' KB';
    return (b/1048576).toFixed(1) + ' MB';
}
function showFilePrev(file) {
    const auto = detectType(file.name);
    if (auto) {
        document.querySelector('.type-radio[value="'+auto+'"]').closest('label').click();
    }
    document.getElementById('prevName').textContent = file.name;
    document.getElementById('prevSize').textContent = fmtBytes(file.size);
    updateIcon();
    document.getElementById('dropDefault').classList.add('hidden');
    document.getElementById('filePreview').classList.remove('hidden');
}

const fileInput = document.getElementById('fileInput');
const dropZone  = document.getElementById('dropZone');
fileInput.addEventListener('change', () => { if (fileInput.files[0]) showFilePrev(fileInput.files[0]); });
dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone.addEventListener('drop', e => {
    e.preventDefault(); dropZone.classList.remove('drag-over');
    if (e.dataTransfer.files[0]) {
        const dt = new DataTransfer(); dt.items.add(e.dataTransfer.files[0]);
        fileInput.files = dt.files; showFilePrev(e.dataTransfer.files[0]);
    }
});

// ── Form validate ──────────────────────────────────────────────────────
document.getElementById('uploadForm').addEventListener('submit', e => {
    if (!document.querySelector('.type-radio:checked')) {
        e.preventDefault(); alert('Please select a file type (PDF, Video, or Word Doc).');
    }
});

// ── Toggle inline preview ──────────────────────────────────────────────
function togglePrev(id) {
    document.getElementById(id)?.classList.toggle('hidden');
}

// Auto-scroll to uploaded unit section
<?php if ($uploaded_unit > 0): ?>
document.addEventListener('DOMContentLoaded', () => {
    const el = document.getElementById('unit-section-<?php echo $uploaded_unit; ?>');
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
});
<?php endif; ?>
</script>
</body>
</html>