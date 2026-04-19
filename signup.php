<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Smart LMS</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-slate-950 min-h-screen flex items-center justify-center p-6 font-sans">
    
    <div class="fixed inset-0 z-0">
        <div class="absolute inset-0 bg-slate-950/80 z-10"></div>
        <video autoplay muted loop class="w-full h-full object-cover">
            <source src="assets/video/hero_bg.mp4" type="video/mp4">
        </video>
    </div>

    <div class="relative z-20 w-full max-w-md bg-white/95 backdrop-blur-xl p-8 rounded-3xl shadow-2xl border border-white/20">
        <div class="text-center mb-6">
            <div class="inline-flex items-center justify-center w-12 h-12 bg-blue-100 rounded-xl mb-3">
                <i class="fa-solid fa-user-plus text-blue-600 text-xl"></i>
            </div>
            <h1 class="text-2xl font-black text-slate-900 uppercase tracking-tight">Join Smart LMS</h1>
            <div class="h-1 w-10 bg-blue-600 mx-auto mt-2 rounded-full"></div>
        </div>

        <form action="process_signup.php" method="POST" class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
                <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Full Name</label>
                <input type="text" name="full_name" placeholder="John Doe" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500 transition-all">
            </div>

            <div class="md:col-span-2">
                <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Institutional Email</label>
                <input type="email" name="email" placeholder="name@institution.com" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold text-slate-800 outline-none focus:ring-2 focus:ring-blue-500 transition-all">
            </div>

            <div>
                <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Role</label>
                <select name="role" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-bold text-slate-700 outline-none appearance-none cursor-pointer">
                    <option value="student">Student</option>
                    <option value="lecturer">Lecturer</option>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Career Focus</label>
                <select name="career_path" class="w-full p-3 bg-slate-100 rounded-xl text-sm font-bold text-slate-700 outline-none appearance-none cursor-pointer">
                    <option value="Software Development">Dev</option>
                    <option value="Data Science">Data</option>
                    <option value="AIS">AIS</option>
                    <option value="Cyber Security">Cyber</option>
                </select>
            </div>

            <div>
                <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Password</label>
                <input type="password" name="password" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div>
                <label class="text-[10px] font-black uppercase text-slate-500 tracking-widest mb-1 block">Confirm</label>
                <input type="password" name="confirm_password" required class="w-full p-3 bg-slate-100 rounded-xl text-sm font-semibold outline-none focus:ring-2 focus:ring-blue-500">
            </div>

            <div class="md:col-span-2 mt-4">
                <button type="submit" class="w-full bg-blue-600 hover:bg-blue-700 text-white font-black py-3.5 rounded-xl text-[11px] uppercase tracking-widest transition-all transform active:scale-95 shadow-xl shadow-blue-600/20">
                    Register Account <i class="fa-solid fa-chevron-right ml-1"></i>
                </button>
                <p class="text-center text-xs text-slate-500 mt-6 font-medium italic">Already registered? <a href="index.php?action=login" class="text-blue-600 font-bold hover:underline">Log In</a></p>
            </div>
        </form>
    </div>
</body>
</html>