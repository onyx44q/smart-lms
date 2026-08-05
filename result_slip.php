<?php
if (session_status() === PHP_SESSION_NONE) session_start();
include 'config.php';

$role = $_SESSION['role'] ?? '';
if (!in_array($role, ['student','lecturer','admin','financial_accountant'])) {
    header("Location: index.php"); exit();
}
$sid = $role === 'student' ? intval($_SESSION['user_id'] ?? 0) : intval($_GET['student_id'] ?? 0);
if (!$sid) { echo "Student not specified."; exit(); }

$student = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT u.id, u.full_name, u.email, u.career_path, u.created_at,
            c.title AS course_name
     FROM users u LEFT JOIN courses c ON c.id=u.course_id
     WHERE u.id=$sid AND u.role='student' LIMIT 1"));
if (!$student) { echo "Student not found."; exit(); }

// Load all units + assessments + marks
$q = mysqli_query($conn, "
    SELECT cu.id AS unit_id, cu.title AS unit_title, cu.unit_code,
           cu.course_id, c.title AS course_title, lec.full_name AS lecturer,
           ua.id AS aid, ua.name AS aname, ua.type AS atype,
           ua.max_mark AS amax, ua.sort_order AS asort,
           um.mark, um.remarks
    FROM unit_registrations ur
    INNER JOIN course_units cu ON cu.id = ur.unit_id
    INNER JOIN courses c       ON c.id  = cu.course_id
    LEFT  JOIN users lec       ON lec.id = cu.lecturer_id
    LEFT  JOIN unit_assessments ua ON ua.unit_id = cu.id
    LEFT  JOIN unit_marks um       ON um.assessment_id = ua.id AND um.student_id = $sid
    WHERE ur.student_id = $sid
    ORDER BY c.title ASC, cu.title ASC, ua.sort_order ASC, ua.id ASC
");

$tree = [];
if ($q) while ($r = mysqli_fetch_assoc($q)) {
    $cid=$r['course_id']; $uid=$r['unit_id'];
    if (!isset($tree[$cid])) $tree[$cid]=['title'=>$r['course_title'],'units'=>[]];
    if (!isset($tree[$cid]['units'][$uid])) $tree[$cid]['units'][$uid]=['title'=>$r['unit_title'],'code'=>$r['unit_code'],'lec'=>$r['lecturer'],'ass'=>[]];
    if ($r['aid']) $tree[$cid]['units'][$uid]['ass'][$r['aid']]=['name'=>$r['aname'],'type'=>$r['atype'],'max'=>(float)$r['amax'],'mark'=>$r['mark']!==null?(float)$r['mark']:null,'remark'=>$r['remarks']];
}

function slip_tot($ass){$tm=$tx=0;$h=false;foreach($ass as $a){$tx+=$a['max'];if($a['mark']!==null){$tm+=$a['mark'];$h=true;}}return$tx>0&&$h?['pct'=>round($tm/$tx*100,1),'tm'=>$tm,'tx'=>$tx]:['pct'=>null,'tm'=>0,'tx'=>$tx];}
function slip_grade($pct){if($pct===null)return['—','Pending','#94a3b8','#f1f5f9'];if($pct>=70)return['A','Distinction','#059669','#d1fae5'];if($pct>=60)return['B','Credit','#2563eb','#dbeafe'];if($pct>=50)return['C','Pass','#7c3aed','#ede9fe'];if($pct>=40)return['D','Pass','#d97706','#fef3c7'];return['F','Fail','#dc2626','#fee2e2'];}
function slip_gp($p){if($p===null)return null;if($p>=70)return 4.0;if($p>=60)return 3.0;if($p>=50)return 2.0;if($p>=40)return 1.0;return 0.0;}

$all_pcts=[];
foreach($tree as $cd) foreach($cd['units'] as $ud){$t=slip_tot($ud['ass']);if($t['pct']!==null)$all_pcts[]=$t['pct'];}
$avg=count($all_pcts)>0?round(array_sum($all_pcts)/count($all_pcts),1):null;
$gps=array_filter(array_map('slip_gp',$all_pcts),fn($v)=>$v!==null);
$gpa=count($gps)>0?round(array_sum($gps)/count($gps),2):null;
[$ov_g,$ov_c,$ov_col,$ov_bg]=slip_grade($avg);
$total_u=array_sum(array_map(fn($c)=>count($c['units']),$tree));
$graded_u=count($all_pcts);
$ref='RS-'.strtoupper(substr(md5($sid.'rs'),0,8));
$gen=date('d M Y, H:i');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Result Slip — <?php echo htmlspecialchars($student['full_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@600;700&family=Inter:wght@400;600;700;800;900&family=JetBrains+Mono:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{margin:0;padding:0;box-sizing:border-box;}
body{background:#e2e8f0;font-family:'Inter',sans-serif;color:#0d1117;}
.bar{background:#0d1117;padding:13px 28px;display:flex;align-items:center;justify-content:space-between;gap:12px;position:sticky;top:0;z-index:100;flex-wrap:wrap;}
.bar span{color:rgba(255,255,255,.45);font-size:13px;}
.btn-p{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:none;background:#3b82f6;color:#fff;text-decoration:none;}
.btn-b{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;border-radius:8px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid rgba(255,255,255,.2);background:transparent;color:#fff;text-decoration:none;}
.doc{max-width:880px;margin:32px auto 60px;background:#fff;box-shadow:0 20px 60px rgba(13,17,23,.2);border-radius:4px;overflow:hidden;}
.dh{background:linear-gradient(135deg,#0d1117,#1e3a5f);position:relative;overflow:hidden;}
.dh::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='40' height='40' viewBox='0 0 40 40' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M20 20.5V18H0v5h20v-2.5zm10 1.5H30v-5H20v5h10zm-10-7V7H0v5h20V15zm10-5H30V5H20v5h10z'/%3E%3C/g%3E%3C/svg%3E");}
.dh-stripe{height:4px;background:#1e40af;}
.dh-inner{padding:36px 52px 28px;position:relative;z-index:1;display:flex;align-items:flex-start;justify-content:space-between;gap:20px;flex-wrap:wrap;}
.dh h1{font-family:'Cormorant Garamond',serif;font-size:30px;font-weight:700;color:#fff;}
.dh-sub{font-size:10px;color:rgba(255,255,255,.4);font-weight:600;letter-spacing:.1em;text-transform:uppercase;margin-top:4px;}
.dh-meta .rl{font-size:9px;color:rgba(255,255,255,.35);font-weight:700;letter-spacing:.1em;text-transform:uppercase;}
.dh-meta .rv{font-family:'JetBrains Mono',monospace;font-size:14px;color:#60a5fa;font-weight:700;margin:3px 0 6px;}
.dh-meta .rd{font-size:11px;color:rgba(255,255,255,.35);}
.dh-meta{text-align:right;}
.dh-inst{padding:11px 52px;background:rgba(255,255,255,.05);border-top:1px solid rgba(255,255,255,.07);display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;position:relative;z-index:1;}
.in{font-size:12px;font-weight:700;color:rgba(255,255,255,.75);}
.is{font-size:10px;color:rgba(255,255,255,.35);margin-top:1px;}
.off{display:flex;align-items:center;gap:7px;background:rgba(59,130,246,.18);border:1px solid rgba(59,130,246,.3);padding:5px 13px;border-radius:20px;}
.off span{font-size:10px;font-weight:700;color:#93c5fd;letter-spacing:.05em;text-transform:uppercase;}
.sp{background:#f8fafc;border-bottom:1px solid #e2e8f0;padding:20px 52px;display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:16px;}
.si .sl{font-size:9px;font-weight:700;color:#94a3b8;letter-spacing:.1em;text-transform:uppercase;margin-bottom:3px;}
.si .sv{font-size:13px;font-weight:700;color:#0d1117;}
.gpa-row{padding:18px 52px;display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:12px;border-bottom:1px solid #e2e8f0;}
.gk{border-radius:10px;padding:13px 15px;border:1px solid #e2e8f0;}
.gk.hi{background:linear-gradient(135deg,#eff6ff,#dbeafe);border-color:#bfdbfe;}
.gk-l{font-size:9px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:.07em;margin-bottom:4px;}
.gk-v{font-family:'JetBrains Mono',monospace;font-size:22px;font-weight:700;}
.gk-s{font-size:10px;color:#64748b;margin-top:2px;}
.cs{border-bottom:1px solid #e2e8f0;}
.cs-hd{background:#f8fafc;padding:14px 52px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;}
.unit-wrap{padding:18px 52px;border-bottom:1px solid #f1f5f9;}
.unit-wrap:last-child{border-bottom:none;}
.ass-chip{border-radius:9px;padding:10px 14px;min-width:110px;border-width:1px;border-style:solid;}
.legend{padding:14px 52px;border-top:1px solid #e2e8f0;background:#f8fafc;}
.lg-chip{display:flex;align-items:center;gap:6px;padding:5px 10px;border-radius:8px;}
.footer{background:#0d1117;padding:22px 52px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;}
.ft{color:rgba(255,255,255,.7);font-weight:700;font-size:13px;margin-bottom:3px;}
.fs{font-size:11px;color:rgba(255,255,255,.3);line-height:1.7;}
.seal{width:54px;height:54px;border:2px dashed rgba(59,130,246,.4);border-radius:50%;display:flex;align-items:center;justify-content:center;margin-left:auto;font-size:24px;}
.fp{font-size:10px;color:rgba(255,255,255,.22);margin-top:5px;text-align:right;}
.no-c{padding:56px;text-align:center;color:#94a3b8;}
@media print{body{background:#fff;}.bar{display:none!important;}.doc{box-shadow:none;margin:0;border-radius:0;}}
@media(max-width:660px){.dh-inner,.dh-inst,.sp,.gpa-row,.cs-hd,.unit-wrap,.legend,.footer{padding-left:18px;padding-right:18px;}}
</style>
</head>
<body>
<div class="bar">
    <div style="display:flex;gap:10px;align-items:center;">
        <?php if($role==='student'): ?><a href="student_dashboard.php" class="btn-b">← Dashboard</a><?php else: ?><a href="javascript:history.back()" class="btn-b">← Back</a><?php endif; ?>
        <span>Result Slip — <?php echo htmlspecialchars($student['full_name']); ?></span>
    </div>
    <button onclick="window.print()" class="btn-p">🖨&nbsp; Print / Save PDF</button>
</div>

<div class="doc">
    <div class="dh">
        <div class="dh-stripe"></div>
        <div class="dh-inner">
            <div><h1>Academic Result Slip</h1><div class="dh-sub">Official Unit Performance Record</div></div>
            <div class="dh-meta"><div class="rl">Reference</div><div class="rv"><?php echo $ref; ?></div><div class="rd">Issued: <?php echo $gen; ?></div></div>
        </div>
        <div class="dh-inst">
            <div><div class="in">SmartLMS Academic Institution</div><div class="is">Examinations &amp; Results Dept.</div></div>
            <div class="off"><span>📋 Official Record</span></div>
        </div>
    </div>

    <div class="sp">
        <div class="si"><div class="sl">Name</div><div class="sv"><?php echo htmlspecialchars($student['full_name']); ?></div></div>
        <div class="si"><div class="sl">Student ID</div><div class="sv" style="font-family:'JetBrains Mono',monospace;">STU-<?php echo str_pad($sid,5,'0',STR_PAD_LEFT); ?></div></div>
        <div class="si"><div class="sl">Email</div><div class="sv" style="font-size:11px;"><?php echo htmlspecialchars($student['email']); ?></div></div>
        <div class="si"><div class="sl">Career Path</div><div class="sv" style="font-size:11px;"><?php echo htmlspecialchars($student['career_path']??'—'); ?></div></div>
        <div class="si"><div class="sl">Academic Year</div><div class="sv"><?php echo date('Y').'/'. (date('Y')+1); ?></div></div>
        <div class="si"><div class="sl">Units</div><div class="sv"><?php echo $graded_u; ?>/<?php echo $total_u; ?> graded</div></div>
    </div>

    <?php if($gpa!==null): ?>
    <div class="gpa-row">
        <div class="gk hi"><div class="gk-l">GPA</div><div class="gk-v"><?php echo number_format($gpa,2); ?></div><div class="gk-s">Out of 4.00</div></div>
        <div class="gk"><div class="gk-l">Average</div><div class="gk-v" style="color:<?php echo $ov_col; ?>;"><?php echo $avg; ?>%</div><div class="gk-s">All graded units</div></div>
        <div class="gk"><div class="gk-l">Grade</div><div class="gk-v" style="color:<?php echo $ov_col; ?>;"><?php echo $ov_g; ?></div><div class="gk-s"><?php echo $ov_c; ?></div></div>
        <div class="gk"><div class="gk-l">Graded</div><div class="gk-v"><?php echo $graded_u; ?>/<?php echo $total_u; ?></div><div class="gk-s">Units</div></div>
    </div>
    <?php endif; ?>

    <?php if(empty($tree)): ?>
    <div class="no-c"><div style="font-size:48px;margin-bottom:14px;">📋</div><div style="font-size:15px;font-weight:700;">No enrolled units found</div></div>
    <?php else: ?>
    <?php foreach($tree as $cid=>$course):
        $c_pcts=[];
        foreach($course['units'] as $ud){$t=slip_tot($ud['ass']);if($t['pct']!==null)$c_pcts[]=$t['pct'];}
        $c_avg=count($c_pcts)>0?round(array_sum($c_pcts)/count($c_pcts),1):null;
        [$c_g,$c_c,$c_col,$c_bg]=slip_grade($c_avg);
    ?>
    <div class="cs">
        <div class="cs-hd">
            <div>
                <div style="font-size:15px;font-weight:900;color:#0f172a;">📘 <?php echo htmlspecialchars($course['title']); ?></div>
                <div style="font-size:10px;color:#64748b;margin-top:2px;"><?php echo count($course['units']); ?> unit<?php echo count($course['units'])!=1?'s':''; ?></div>
            </div>
            <?php if($c_avg!==null): ?>
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="width:46px;height:46px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:20px;font-weight:900;background:<?php echo $c_bg; ?>;color:<?php echo $c_col; ?>;border:2px solid <?php echo $c_col; ?>;"><?php echo $c_g; ?></div>
                <div><div style="font-size:20px;font-weight:900;color:<?php echo $c_col; ?>;font-family:'JetBrains Mono',monospace;"><?php echo $c_avg; ?>%</div><div style="font-size:10px;color:#94a3b8;"><?php echo $c_c; ?></div></div>
            </div>
            <?php endif; ?>
        </div>

        <?php foreach($course['units'] as $uid=>$unit):
            $ut=slip_tot($unit['ass']);
            [$u_g,$u_c,$u_col,$u_bg]=slip_grade($ut['pct']);
            $bar_c=$ut['pct']===null?'#e2e8f0':($ut['pct']>=70?'#10b981':($ut['pct']>=50?'#6366f1':($ut['pct']>=40?'#f59e0b':'#ef4444')));
        ?>
        <div class="unit-wrap">
            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                <div>
                    <?php if($unit['code']): ?><span style="font-family:'JetBrains Mono',monospace;font-size:10px;font-weight:700;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;padding:2px 8px;border-radius:4px;margin-right:6px;"><?php echo htmlspecialchars($unit['code']); ?></span><?php endif; ?>
                    <span style="font-size:15px;font-weight:800;color:#1e293b;"><?php echo htmlspecialchars($unit['title']); ?></span>
                    <?php if($unit['lec']): ?><span style="font-size:10px;color:#94a3b8;margin-left:6px;">· <?php echo htmlspecialchars($unit['lec']); ?></span><?php endif; ?>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div style="width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;font-weight:900;background:<?php echo $u_bg; ?>;color:<?php echo $u_col; ?>;border:2px solid <?php echo $u_col; ?>33;"><?php echo $u_g; ?></div>
                    <div style="text-align:right;">
                        <div style="font-size:18px;font-weight:900;font-family:'JetBrains Mono',monospace;color:<?php echo $u_col; ?>;"><?php echo $ut['pct']!==null?$ut['pct'].'%':'Pending'; ?></div>
                        <div style="font-size:10px;color:#94a3b8;"><?php echo $ut['pct']!==null?number_format($ut['tm'],1).' / '.number_format($ut['tx'],1).' marks':'No marks yet'; ?></div>
                    </div>
                </div>
            </div>
            <?php if($ut['pct']!==null): ?>
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:14px;">
                <span style="font-size:10px;color:#94a3b8;width:20px;">0%</span>
                <div style="flex:1;height:8px;background:#f1f5f9;border-radius:4px;overflow:hidden;">
                    <div style="width:<?php echo min(100,$ut['pct']); ?>%;height:100%;background:<?php echo $bar_c; ?>;border-radius:4px;"></div>
                </div>
                <span style="font-size:11px;font-weight:800;color:<?php echo $u_col; ?>;width:36px;text-align:right;"><?php echo $ut['pct']; ?>%</span>
            </div>
            <?php endif; ?>
            <?php if(!empty($unit['ass'])): ?>
            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                <?php foreach($unit['ass'] as $a):
                    $is_e=$a['type']==='exam';
                    $ac=$is_e?'#dc2626':'#6366f1'; $abg=$is_e?'#fef2f2':'#ede9fe';
                    $ap=($a['max']>0&&$a['mark']!==null)?round($a['mark']/$a['max']*100,1):null;
                ?>
                <div class="ass-chip" style="background:<?php echo $abg; ?>;border-color:<?php echo $ac; ?>22;">
                    <div style="font-size:9px;font-weight:800;color:<?php echo $ac; ?>;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px;"><?php echo $is_e?'📝 Exam':'📚 C/W'; ?></div>
                    <div style="font-size:11px;font-weight:700;color:#475569;margin-bottom:5px;"><?php echo htmlspecialchars(ucwords($a['name'])); ?></div>
                    <div style="font-size:17px;font-weight:900;font-family:'JetBrains Mono',monospace;color:<?php echo $a['mark']!==null?$ac:'#cbd5e1'; ?>;">
                        <?php echo $a['mark']!==null?number_format($a['mark'],1):'—'; ?>
                        <span style="font-size:11px;font-weight:500;color:#94a3b8;"> / <?php echo number_format($a['max'],1); ?></span>
                    </div>
                    <?php if($ap!==null): ?><div style="font-size:10px;font-weight:700;color:<?php echo $ac; ?>;margin-top:2px;"><?php echo $ap; ?>%</div><?php endif; ?>
                    <?php if(!empty($a['remark'])): ?><div style="font-size:10px;color:#64748b;margin-top:3px;font-style:italic;"><?php echo htmlspecialchars($a['remark']); ?></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="font-size:12px;color:#94a3b8;font-style:italic;">No assessments entered yet.</div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="legend">
        <div style="font-size:9px;font-weight:800;color:#94a3b8;text-transform:uppercase;letter-spacing:.1em;margin-bottom:9px;">Grading Scale</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <?php foreach([['A','70–100%','Distinction','#059669','#ecfdf5'],['B','60–69%','Credit','#2563eb','#eff6ff'],['C','50–59%','Pass','#7c3aed','#f5f3ff'],['D','40–49%','Pass','#d97706','#fffbeb'],['F','0–39%','Fail','#dc2626','#fef2f2']] as [$gl,$gr,$gn,$gc,$gbg]): ?>
            <div class="lg-chip" style="background:<?php echo $gbg; ?>;">
                <span style="font-size:14px;font-weight:900;color:<?php echo $gc; ?>;font-family:'JetBrains Mono',monospace;"><?php echo $gl; ?></span>
                <div><div style="font-size:10px;font-weight:700;color:<?php echo $gc; ?>;"><?php echo $gr; ?></div><div style="font-size:9px;color:#64748b;"><?php echo $gn; ?></div></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="footer">
        <div><div class="ft">SmartLMS Examination Office</div><div class="fs">Computer-generated — valid without physical signature.<br>Appeals within 14 days: examinations@smartlms.com<br>Results subject to Academic Board ratification.</div></div>
        <div><div class="seal">🎓</div><div class="fp">Official Academic Record</div></div>
    </div>
</div>
</body>
</html>
