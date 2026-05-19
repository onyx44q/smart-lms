<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Institutional Smart LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        .slide {
            position: absolute;
            width: 100%; height: 100%;
            opacity: 0;
            background-size: cover;
            background-position: center;
            animation: slideShow 25s infinite;
        }
        .slide:nth-child(1) { background-image: url('https://images.unsplash.com/photo-1522202176988-66273c2fd55f?auto=format&fit=crop&w=1920&q=80'); animation-delay: 0s; }
        .slide:nth-child(2) { background-image: url('https://images.unsplash.com/photo-1501504905252-473c47e087f8?auto=format&fit=crop&w=1920&q=80'); animation-delay: 5s; }
        .slide:nth-child(3) { background-image: url('https://images.unsplash.com/photo-1523240795612-9a054b0db644?auto=format&fit=crop&w=1920&q=80'); animation-delay: 10s; }
        .slide:nth-child(4) { background-image: url('https://images.unsplash.com/photo-1434030216411-0b793f4b4173?auto=format&fit=crop&w=1920&q=80'); animation-delay: 15s; }
        .slide:nth-child(5) { background-image: url('https://images.unsplash.com/photo-1524178232363-1fb280714553?auto=format&fit=crop&w=1920&q=80'); animation-delay: 20s; }

        @keyframes slideShow {
            0% { opacity: 0; transform: scale(1); }
            5% { opacity: 1; }
            20% { opacity: 1; }
            25% { opacity: 0; transform: scale(1.05); }
            100% { opacity: 0; }
        }

        .accent-text { animation: colorShift 25s infinite; }
        @keyframes colorShift {
            0%, 20% { color: #60a5fa; }
            25%, 45% { color: #facc15; }
            50%, 70% { color: #4ade80; }
            75%, 95% { color: #f87171; }
            100% { color: #60a5fa; }
        }

        .transparent-outline { color: transparent; -webkit-text-stroke: 1px rgba(255,255,255,0.4); }
        .modal-hidden { opacity: 0; visibility: hidden; pointer-events: none; transform: scale(0.95); }
        .modal-visible { opacity: 1; visibility: visible; transform: scale(1); transition: all 0.3s ease; }
        
        .scroll-bar { width: 2px; height: 60px; background: linear-gradient(to bottom, transparent, #fff); animation: scrollBounce 2s infinite; }
        @keyframes scrollBounce { 0%, 100% { transform: translateY(0); opacity: 0.2; } 50% { transform: translateY(10px); opacity: 1; } }
    </style>
</head>
<body class="bg-slate-950 overflow-hidden font-sans text-slate-200">

    <?php if(isset($_GET['status']) || isset($_GET['error'])): ?>
        <div class="fixed top-24 left-1/2 -translate-x-1/2 z-[110]">
            <?php if(isset($_GET['status']) && $_GET['status'] == 'registered'): ?>
                <div id="notif" class="bg-green-500 text-white px-6 py-3 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-2xl flex items-center animate-bounce">
                    <i class="fa-solid fa-circle-check mr-2"></i> Account Registered Successfully!
                </div>
            <?php elseif(isset($_GET['status']) && $_GET['status'] == 'loggedout'): ?>
                <div id="notif" class="bg-blue-500 text-white px-6 py-3 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-2xl flex items-center">
                    <i class="fa-solid fa-info-circle mr-2"></i> Successfully Logged Out.
                </div>
            <?php elseif(isset($_GET['error']) || (isset($_GET['status']) && $_GET['status'] == 'invalid')): ?>
                <div id="notif" class="bg-red-500 text-white px-6 py-3 rounded-xl font-bold text-[10px] uppercase tracking-widest shadow-2xl flex items-center">
                    <i class="fa-solid fa-triangle-exclamation mr-2"></i> Invalid Login Credentials.
                </div>
            <?php endif; ?>
        </div>
        <script>setTimeout(() => { const n = document.getElementById('notif'); if(n) { n.style.opacity = "0"; setTimeout(() => n.remove(), 500); } }, 3000);</script>
    <?php endif; ?>

    <div class="fixed inset-0 z-0">
        <div class="slide"></div><div class="slide"></div><div class="slide"></div><div class="slide"></div><div class="slide"></div>
        <div class="absolute inset-0 bg-slate-950/70 z-10"></div>
    </div>

    <nav class="fixed top-0 left-0 z-50 w-full px-12 py-8 flex justify-between items-center">
        <div class="flex items-center space-x-2">
            <i class="fa-solid fa-brain accent-text text-xl"></i>
            <span class="text-white font-black tracking-tighter text-lg uppercase">Smart<span class="accent-text">LMS</span></span>
        </div>
        <div class="flex items-center space-x-10 text-[13px] font-black uppercase tracking-widest">
            <button onclick="toggleModal()" class="text-white hover:text-blue-400 transition-all duration-300">Login</button>
            <a href="signup.php" class="bg-white text-slate-950 px-8 py-3 rounded-full hover:bg-slate-200 transition-all duration-300 shadow-lg shadow-white/10">Join Now</a>
        </div>
    </nav>

    <main class="relative z-20 h-screen flex flex-col justify-center px-12 md:px-24 max-w-4xl">
        <div class="space-y-4">
            <h2 class="transparent-outline text-4xl md:text-5xl font-black uppercase italic tracking-tighter">Personalized</h2>
            <h1 class="text-white text-5xl md:text-7xl font-black uppercase leading-tight">Learning <br> <span class="accent-text">Redefined</span></h1>
            <p class="text-slate-400 text-sm max-w-md font-medium leading-relaxed border-l-2 border-slate-700 pl-6">Experience an education journey where the curriculum evolves with your performance.</p>
            <div class="flex items-center space-x-4 pt-4">
                <button onclick="toggleModal()" class="bg-blue-600 hover:bg-blue-700 text-white px-8 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all shadow-lg shadow-blue-600/20">Get Started</button>
                <button class="bg-white/5 hover:bg-white/10 text-white border border-white/10 px-8 py-3 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all">View Courses</button>
            </div>
        </div>
    </main>

    <div id="loginModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-slate-950/90 backdrop-blur-xl modal-hidden p-6">
        <div class="relative w-full max-w-sm bg-white p-8 rounded-3xl shadow-2xl border border-slate-200">
            <button onclick="toggleModal()" class="absolute top-4 right-4 text-slate-400 hover:text-slate-900 transition-all"><i class="fa-solid fa-circle-xmark text-xl"></i></button>
            <div class="text-center mb-6">
                <h3 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Portal Login</h3>
                <div class="h-1 w-10 bg-blue-600 mx-auto mt-2 rounded-full"></div>
            </div>
            <form action="process_login.php" method="POST" class="space-y-4">
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Role</label>
                    <select name="role" class="w-full p-3 bg-slate-100 rounded-xl outline-none text-sm font-bold text-slate-700 appearance-none focus:ring-2 focus:ring-blue-500">
                        <option value="student">Student</option>
                        <option value="lecturer">Lecturer</option>
                        <option value="admin">Admin</option>
                        <option value="financial_accountant">Financial Accountant</option>
                    </select>
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Email</label>
                    <input type="email" name="email" placeholder="name@email.com" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <div>
                    <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Password</label>
                    <input type="password" name="password" placeholder="••••••••" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500">
                </div>
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white py-3.5 rounded-xl text-[11px] font-black uppercase tracking-widest transition-all transform active:scale-95 shadow-lg">Authorize & Enter</button>
            </form>
            <p class="text-center mt-5 text-xs text-slate-500 font-medium italic">New? <a href="signup.php" class="text-blue-600 font-bold hover:underline">Create Account</a></p>
        </div>
    </div>

    <script>
        function toggleModal() {
            const m = document.getElementById('loginModal');
            m.classList.toggle('modal-hidden');
            m.classList.toggle('modal-visible');
        }
        window.onload = function() {
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('action') === 'login') { toggleModal(); }
        }
        window.onclick = function(e) { if (e.target == document.getElementById('loginModal')) toggleModal(); }
    </script>
</body>
</html>