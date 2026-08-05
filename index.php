<?php
// index.php — SmartLMS Login Page with Mixed Video + Image Background
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Smart LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        /* ── Background layer ── */
        .bg-layer {
            position: fixed;
            inset: 0;
            z-index: 0;
        }

        /* ── Each media item (image or video wrapper) ── */
        .bg-item {
            position: absolute;
            inset: 0;
            opacity: 0;
            transition: opacity 1.6s ease-in-out;
        }
        .bg-item.active {
            opacity: 1;
        }

        /* ── Image slides ── */
        .bg-img {
            width: 100%; height: 100%;
            background-size: cover;
            background-position: center;
            animation: slowZoom 8s ease-in-out infinite alternate;
        }
        @keyframes slowZoom {
            from { transform: scale(1);    }
            to   { transform: scale(1.06); }
        }

        /* ── Video slides ── */
        .bg-vid {
            width: 100%; height: 100%;
            object-fit: cover;
            object-position: center;
        }

        /* ── Dark overlay ── */
        .bg-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(
                135deg,
                rgba(2,6,23,.75) 0%,
                rgba(15,23,42,.55) 50%,
                rgba(2,6,23,.8) 100%
            );
            z-index: 2;
        }

        /* ── Animated grain texture ── */
        .bg-noise {
            position: absolute;
            inset: 0;
            z-index: 3;
            opacity: .035;
            background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='noise'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23noise)'/%3E%3C/svg%3E");
            animation: grainShift 0.4s steps(1) infinite;
        }
        @keyframes grainShift {
            0%  { transform: translate(0,0); }
            25% { transform: translate(-1px,1px); }
            50% { transform: translate(1px,-1px); }
            75% { transform: translate(-1px,-1px); }
            100%{ transform: translate(1px,1px); }
        }

        /* ── Slide counter dots ── */
        .slide-dots {
            position: fixed;
            bottom: 28px;
            left: 50%;
            transform: translateX(-50%);
            display: flex;
            gap: 8px;
            z-index: 50;
        }
        .slide-dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: rgba(255,255,255,.3);
            transition: all .4s ease;
            cursor: pointer;
        }
        .slide-dot.active {
            background: #fff;
            width: 22px;
            border-radius: 3px;
        }

        /* ── Slide type badge ── */
        .media-badge {
            position: fixed;
            bottom: 60px;
            right: 28px;
            z-index: 50;
            display: flex;
            align-items: center;
            gap: 6px;
            background: rgba(255,255,255,.1);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255,255,255,.15);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 10px;
            font-weight: 700;
            color: rgba(255,255,255,.6);
            letter-spacing: .06em;
            text-transform: uppercase;
            transition: all .5s ease;
        }
        .media-badge .dot {
            width: 6px; height: 6px;
            border-radius: 50%;
            background: #ef4444;
            animation: blink 1s infinite;
        }
        .media-badge.is-image .dot { background: #60a5fa; animation: none; }
        @keyframes blink { 0%,100%{opacity:1;} 50%{opacity:.3;} }

        /* ── Accent color animation ── */
        .accent-text { animation: colorShift 30s linear infinite; }
        @keyframes colorShift {
            0%,18%  { color: #60a5fa; }
            20%,38% { color: #a78bfa; }
            40%,58% { color: #34d399; }
            60%,78% { color: #facc15; }
            80%,98% { color: #f87171; }
            100%     { color: #60a5fa; }
        }

        .transparent-outline {
            color: transparent;
            -webkit-text-stroke: 1px rgba(255,255,255,0.35);
        }

        /* ── Modal ── */
        .modal-hidden  { opacity:0; visibility:hidden; pointer-events:none; transform:scale(0.96) translateY(8px); }
        .modal-visible { opacity:1; visibility:visible; transform:scale(1) translateY(0); transition:all .35s cubic-bezier(.34,1.56,.64,1); }

        /* ── Glassmorphism login card ── */
        .login-card {
            background: rgba(255,255,255,.97);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,.8);
            box-shadow: 0 32px 80px rgba(0,0,0,.4), 0 0 0 1px rgba(255,255,255,.05) inset;
        }

        /* ── Progress bar for slide timing ── */
        .slide-progress {
            position: fixed;
            bottom: 0;
            left: 0;
            height: 2px;
            background: linear-gradient(90deg, #6366f1, #a78bfa, #60a5fa);
            z-index: 50;
            transition: width linear;
        }

        /* ── Vertical text ── */
        .vert-text {
            writing-mode: vertical-rl;
            text-orientation: mixed;
            transform: rotate(180deg);
        }

        /* ── Notification ── */
        .notif-enter { animation: notifIn .4s cubic-bezier(.34,1.56,.64,1) forwards; }
        @keyframes notifIn { from{opacity:0;transform:translateY(-20px) scale(.9);} to{opacity:1;transform:translateY(0) scale(1);} }
    </style>
</head>
<body class="bg-slate-950 overflow-hidden font-sans text-slate-200">

    <!-- ── Notifications ── -->
    <?php if(isset($_GET['status']) || isset($_GET['error'])): ?>
    <div class="fixed top-6 left-1/2 -translate-x-1/2 z-[200]">
        <?php if(($_GET['status']??'') === 'registered'): ?>
        <div id="notif" class="notif-enter bg-emerald-500 text-white px-6 py-3 rounded-2xl font-bold text-[10px] uppercase tracking-widest shadow-2xl flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> Account Registered Successfully!
        </div>
        <?php elseif(($_GET['status']??'') === 'loggedout'): ?>
        <div id="notif" class="notif-enter bg-blue-500 text-white px-6 py-3 rounded-2xl font-bold text-[10px] uppercase tracking-widest shadow-2xl flex items-center gap-2">
            <i class="fa-solid fa-right-from-bracket"></i> Successfully Logged Out.
        </div>
        <?php elseif(isset($_GET['error']) || ($_GET['status']??'') === 'invalid'): ?>
        <div id="notif" class="notif-enter bg-red-500 text-white px-6 py-3 rounded-2xl font-bold text-[10px] uppercase tracking-widest shadow-2xl flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> Invalid Login Credentials.
        </div>
        <?php endif; ?>
    </div>
    <script>setTimeout(()=>{const n=document.getElementById('notif');if(n){n.style.transition='opacity .5s';n.style.opacity='0';setTimeout(()=>n.remove(),500);}},3500);</script>
    <?php endif; ?>

    <!-- ══════════════════════════════════════
         MIXED BACKGROUND: IMAGES + VIDEOS
    ══════════════════════════════════════ -->
    <div class="bg-layer" id="bgLayer">

        <!-- ITEM 1: Image — Students collaborating -->
        <div class="bg-item active" data-type="image">
            <div class="bg-img" style="background-image:url('https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1920&q=80');"></div>
        </div>

        <!-- ITEM 2: Video — Campus / Study -->
        <div class="bg-item" data-type="video">
            <video class="bg-vid" autoplay muted loop playsinline
                   onerror="this.closest('.bg-item').style.background='linear-gradient(135deg,#1e3a5f,#0f172a)'">
                <source src="https://videos.pexels.com/video-files/3195394/3195394-uhd_2560_1440_25fps.mp4" type="video/mp4">
            </video>
        </div>

        <!-- ITEM 3: Image — Library / books -->
        <div class="bg-item" data-type="image">
            <div class="bg-img" style="background-image:url('https://images.unsplash.com/photo-1519389950473-47ba0277781c?auto=format&fit=crop&w=1920&q=80');"></div>
        </div>

        <!-- ITEM 4: Video — Technology / learning -->
        <div class="bg-item" data-type="video">
            <video class="bg-vid" autoplay muted loop playsinline
                   onerror="this.closest('.bg-item').style.background='linear-gradient(135deg,#1e1b4b,#0f172a)'">
                <source src="https://videos.pexels.com/video-files/3129671/3129671-uhd_2560_1440_30fps.mp4" type="video/mp4">
            </video>
        </div>

        <!-- ITEM 5: Image — Lecture hall -->
        <div class="bg-item" data-type="image">
            <div class="bg-img" style="background-image:url('https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=1920&q=80');"></div>
        </div>

        <!-- ITEM 6: Video — Students walking campus -->
        <div class="bg-item" data-type="video">
            <video class="bg-vid" autoplay muted loop playsinline
                   onerror="this.closest('.bg-item').style.background='linear-gradient(135deg,#064e3b,#0f172a)'">
                <source src="https://videos.pexels.com/video-files/3209828/3209828-uhd_2560_1440_25fps.mp4" type="video/mp4">
            </video>
        </div>

        <!-- ITEM 7: Image — Graduation / achievement -->
        <div class="bg-item" data-type="image">
            <div class="bg-img" style="background-image:url('https://images.unsplash.com/photo-1543269865-cbf427effbad?auto=format&fit=crop&w=1920&q=80');"></div>
        </div>

        <!-- Overlay + grain -->
        <div class="bg-overlay" style="z-index:4;"></div>
        <div class="bg-noise" style="z-index:5;"></div>
    </div>

    <!-- ── Progress bar ── -->
    <div class="slide-progress" id="slideProgress" style="width:0%;"></div>

    <!-- ── Media type badge ── -->
    <div class="media-badge is-image" id="mediaBadge">
        <span class="dot"></span>
        <span id="badgeText">Photo</span>
    </div>

    <!-- ── Slide dots ── -->
    <div class="slide-dots" id="slideDots"></div>

    <!-- ── Vertical brand tag (left side) ── -->
    <div class="fixed left-6 top-1/2 -translate-y-1/2 z-40 hidden lg:flex flex-col items-center gap-4">
        <div class="w-px h-16 bg-gradient-to-b from-transparent to-white/30"></div>
        <span class="vert-text text-[9px] font-black uppercase tracking-[0.3em] text-white/25">Academic Excellence</span>
        <div class="w-px h-16 bg-gradient-to-t from-transparent to-white/30"></div>
    </div>

    <!-- ── Navbar ── -->
    <nav class="fixed top-0 left-0 z-50 w-full px-10 py-7 flex justify-between items-center">
        <div class="flex items-center gap-3">
            <div class="w-9 h-9 rounded-xl bg-white/10 backdrop-blur border border-white/20 flex items-center justify-center">
                <i class="fa-solid fa-brain accent-text text-base"></i>
            </div>
            <div>
                <span class="text-white font-black tracking-tighter text-lg uppercase leading-none">Smart<span class="accent-text">LMS</span></span>
                <div class="text-[8px] text-white/30 font-bold uppercase tracking-widest">Learning Management</div>
            </div>
        </div>
        <div class="flex items-center gap-8 text-[11px] font-black uppercase tracking-widest">
            <button onclick="toggleModal()" class="text-white/70 hover:text-white transition-all duration-300 flex items-center gap-2">
                <i class="fa-solid fa-right-to-bracket text-xs"></i> Login
            </button>
            <a href="signup.php" class="bg-white text-slate-950 px-7 py-2.5 rounded-full hover:bg-slate-100 transition-all duration-300 shadow-lg shadow-black/20 flex items-center gap-2">
                <i class="fa-solid fa-user-plus text-xs"></i> Join Now
            </a>
        </div>
    </nav>

    <!-- ── Hero Content ── -->
    <main class="relative z-20 h-screen flex flex-col justify-center px-10 md:px-20 max-w-5xl">
        <div class="space-y-5">
            <!-- Label -->
            <div class="flex items-center gap-3">
                <div class="w-8 h-px bg-white/40"></div>
                <span class="text-[10px] font-black uppercase tracking-[0.25em] text-white/50">Next-Gen Education Platform</span>
            </div>
            <h2 class="transparent-outline text-4xl md:text-5xl font-black uppercase italic tracking-tighter leading-none">Personalized</h2>
            <h1 class="text-white text-5xl md:text-7xl font-black uppercase leading-none tracking-tight">
                Learning <br>
                <span class="accent-text">Redefined</span>
            </h1>
            <p class="text-white/40 text-sm max-w-md font-medium leading-relaxed border-l-2 border-white/10 pl-5">
                Experience an education journey where the curriculum evolves with your performance, pace and potential.
            </p>
            <div class="flex items-center gap-4 pt-3">
                <button onclick="toggleModal()" class="group bg-white/10 hover:bg-white/20 backdrop-blur border border-white/20 hover:border-white/40 text-white px-8 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all flex items-center gap-2">
                    <i class="fa-solid fa-key text-xs group-hover:rotate-12 transition-transform"></i> Portal Login
                </button>
                <a href="signup.php" class="text-white/50 hover:text-white text-[10px] font-black uppercase tracking-widest transition-all flex items-center gap-2">
                    Create Account <i class="fa-solid fa-arrow-right text-xs"></i>
                </a>
            </div>

            <!-- Live stats strip -->
            <div class="flex items-center gap-8 pt-4 border-t border-white/10 mt-6">
                <div>
                    <div class="text-white font-black text-xl">5+</div>
                    <div class="text-white/30 text-[9px] font-bold uppercase tracking-widest">Modules</div>
                </div>
                <div class="w-px h-8 bg-white/10"></div>
                <div>
                    <div class="text-white font-black text-xl">AI</div>
                    <div class="text-white/30 text-[9px] font-bold uppercase tracking-widest">Powered</div>
                </div>
                <div class="w-px h-8 bg-white/10"></div>
                <div>
                    <div class="text-white font-black text-xl">24/7</div>
                    <div class="text-white/30 text-[9px] font-bold uppercase tracking-widest">Access</div>
                </div>
                <div class="w-px h-8 bg-white/10"></div>
                <div>
                    <div class="text-white font-black text-xl">100%</div>
                    <div class="text-white/30 text-[9px] font-bold uppercase tracking-widest">Online</div>
                </div>
            </div>
        </div>
    </main>

    <!-- ══════════════════════════════════════
         LOGIN MODAL
    ══════════════════════════════════════ -->
    <div id="loginModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/80 backdrop-blur-2xl modal-hidden p-6">
        <div class="login-card relative w-full max-w-sm p-8 rounded-3xl">

            <!-- Close -->
            <button onclick="toggleModal()" class="absolute top-4 right-4 w-8 h-8 rounded-full bg-slate-100 hover:bg-slate-200 text-slate-400 hover:text-slate-700 transition-all flex items-center justify-center">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>

            <!-- Header -->
            <div class="text-center mb-7">
                <div class="w-14 h-14 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 flex items-center justify-center mx-auto mb-4 shadow-lg shadow-blue-600/30">
                    <i class="fa-solid fa-brain text-white text-xl"></i>
                </div>
                <h3 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Portal Login</h3>
                <p class="text-slate-400 text-xs mt-1">Sign in to your SmartLMS account</p>
            </div>

            <form action="process_login.php" method="POST" class="space-y-4">
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1.5 block">Role</label>
                    <div class="relative">
                        <i class="fa-solid fa-user-shield absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <select name="role" class="w-full pl-9 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl outline-none text-sm font-bold text-slate-700 appearance-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                            <option value="student">Student</option>
                            <option value="lecturer">Lecturer</option>
                            <option value="admin">Admin</option>
                            <option value="financial_accountant">Financial Accountant</option>
                            <option value="boarding_master">Boarding Master</option>
                            <option value="hr_manager">HR Manager</option>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1.5 block">Email or Admission No.</label>
                    <div class="relative">
                        <i class="fa-solid fa-at absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="text" name="email" placeholder="Email or Admission Number" required
                               class="w-full pl-9 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                    </div>
                </div>

                <div>
                    <label class="text-[10px] font-black uppercase text-slate-400 tracking-widest mb-1.5 block">Password</label>
                    <div class="relative">
                        <i class="fa-solid fa-lock absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 text-xs"></i>
                        <input type="password" name="password" id="pwd" placeholder="••••••••" required
                               class="w-full pl-9 pr-10 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500 focus:border-blue-500 transition-all">
                        <button type="button" onclick="togglePwd()" class="absolute right-3 top-1/2 -translate-y-1/2 text-slate-400 hover:text-slate-600 transition-all">
                            <i class="fa-solid fa-eye text-xs" id="eye-icon"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-gradient-to-r from-blue-600 to-indigo-700 hover:from-blue-700 hover:to-indigo-800 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all transform active:scale-[.98] shadow-lg shadow-blue-600/30 flex items-center justify-center gap-2 mt-2">
                    <i class="fa-solid fa-right-to-bracket"></i> Authorize &amp; Enter
                </button>
            </form>

            <div class="mt-5 pt-4 border-t border-slate-100 text-center">
                <p class="text-xs text-slate-400 font-medium">
                    New student? <a href="signup.php" class="text-blue-600 font-bold hover:text-blue-700 hover:underline">Create Account</a>
                </p>
            </div>
        </div>
    </div>

    <script>
    // ── Toggle Modal ──────────────────────────────────────────
    function toggleModal() {
        const m = document.getElementById('loginModal');
        m.classList.toggle('modal-hidden');
        m.classList.toggle('modal-visible');
    }
    function togglePwd() {
        const p = document.getElementById('pwd');
        const i = document.getElementById('eye-icon');
        if (p.type === 'password') { p.type = 'text'; i.classList.replace('fa-eye','fa-eye-slash'); }
        else { p.type = 'password'; i.classList.replace('fa-eye-slash','fa-eye'); }
    }
    window.onclick = e => { if (e.target === document.getElementById('loginModal')) toggleModal(); }
    window.onload  = () => { if (new URLSearchParams(location.search).get('action')==='login') toggleModal(); }

    // ── Mixed Background Controller ───────────────────────────
    const ITEMS    = Array.from(document.querySelectorAll('.bg-item'));
    const DURATIONS = ITEMS.map(el => el.dataset.type === 'video' ? 9000 : 7000); // video=9s, image=7s
    const TOTAL = ITEMS.length;
    let current  = 0;
    let timer    = null;
    let progTimer= null;

    // Build dots
    const dotsEl = document.getElementById('slideDots');
    ITEMS.forEach((_,i) => {
        const d = document.createElement('div');
        d.className = 'slide-dot' + (i===0?' active':'');
        d.onclick = () => goTo(i);
        dotsEl.appendChild(d);
    });

    function updateDots(idx) {
        document.querySelectorAll('.slide-dot').forEach((d,i) => {
            d.classList.toggle('active', i === idx);
        });
    }

    function updateBadge(type) {
        const badge = document.getElementById('mediaBadge');
        const txt   = document.getElementById('badgeText');
        if (type === 'video') {
            badge.classList.remove('is-image');
            txt.textContent = 'Live Video';
        } else {
            badge.classList.add('is-image');
            txt.textContent = 'Photo';
        }
    }

    function animateProgress(duration) {
        const bar = document.getElementById('slideProgress');
        bar.style.transition = 'none';
        bar.style.width = '0%';
        // force reflow
        bar.offsetWidth;
        bar.style.transition = `width ${duration}ms linear`;
        bar.style.width = '100%';
    }

    function goTo(idx) {
        // Fade out current
        ITEMS[current].classList.remove('active');
        // Fade in next
        current = (idx + TOTAL) % TOTAL;
        ITEMS[current].classList.add('active');
        // Play video if it's a video slide
        const vid = ITEMS[current].querySelector('video');
        if (vid) { vid.currentTime = 0; vid.play().catch(()=>{}); }
        // Update UI
        updateDots(current);
        updateBadge(ITEMS[current].dataset.type);
        animateProgress(DURATIONS[current]);
        // Schedule next
        clearTimeout(timer);
        timer = setTimeout(() => goTo(current + 1), DURATIONS[current]);
    }

    // Preload videos — skip to next slide if video fails to load
    document.querySelectorAll('.bg-vid').forEach((vid) => {
        vid.load();
        vid.addEventListener('error', () => {
            // Find which item this video belongs to
            const item = vid.closest('.bg-item');
            const idx  = ITEMS.indexOf(item);
            // If this is the currently active slide, skip forward
            if (idx === current) {
                clearTimeout(timer);
                goTo(current + 1);
            }
            // Replace failed video with gradient background
            item.style.background = 'linear-gradient(135deg,#1e3a5f,#0f172a)';
        });
    });

    // Start
    updateBadge(ITEMS[0].dataset.type);
    animateProgress(DURATIONS[0]);
    timer = setTimeout(() => goTo(1), DURATIONS[0]);

    // Pause videos not currently shown to save bandwidth
    function pauseOtherVideos(activeIdx) {
        ITEMS.forEach((item, i) => {
            if (i !== activeIdx) {
                const v = item.querySelector('video');
                if (v) v.pause();
            }
        });
        const activeVid = ITEMS[activeIdx].querySelector('video');
        if (activeVid) activeVid.play().catch(()=>{});
    }

    // Override goTo to also handle video pause/play
    const _goTo = goTo;
    function goTo(idx) {
        ITEMS[current].classList.remove('active');
        current = (idx + TOTAL) % TOTAL;
        ITEMS[current].classList.add('active');
        pauseOtherVideos(current);
        updateDots(current);
        updateBadge(ITEMS[current].dataset.type);
        animateProgress(DURATIONS[current]);
        clearTimeout(timer);
        timer = setTimeout(() => goTo(current + 1), DURATIONS[current]);
    }

    // Keyboard nav
    document.addEventListener('keydown', e => {
        if (e.key === 'ArrowRight') goTo(current + 1);
        if (e.key === 'ArrowLeft')  goTo(current - 1);
    });
    </script>
</body>
</html>
