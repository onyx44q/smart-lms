<?php
include 'config.php';
checkRole('admin');

$type = $_GET['type']; // 'course' or 'lecturer'
$id = intval($_GET['id']);

if ($type == 'course') {
    $data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM courses WHERE id = $id"));
} else {
    $data = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM users WHERE id = $id"));
}

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    if ($type == 'course') {
        $val = mysqli_real_escape_string($conn, $_POST['title']);
        mysqli_query($conn, "UPDATE courses SET title = '$val' WHERE id = $id");
        header("Location: admin_dashboard.php?view=courses");
    } else {
        $name = mysqli_real_escape_string($conn, $_POST['full_name']);
        $email = mysqli_real_escape_string($conn, $_POST['email']);
        mysqli_query($conn, "UPDATE users SET full_name = '$name', email = '$email' WHERE id = $id");
        header("Location: admin_dashboard.php?view=users");
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <title>Edit <?php echo ucfirst($type); ?></title>
</head>
<body class="bg-slate-100 flex items-center justify-center min-h-screen p-6">
    <div class="bg-white p-8 rounded-3xl shadow-xl w-full max-w-md border border-slate-200">
        <h2 class="text-2xl font-bold text-slate-800 mb-6">Edit <?php echo ucfirst($type); ?></h2>
        <form method="POST" class="space-y-4">
            <?php if($type == 'course'): ?>
                <label class="text-xs font-bold text-slate-400 uppercase">Course Title</label>
                <input type="text" name="title" value="<?php echo $data['title']; ?>" class="w-full p-4 bg-slate-50 rounded-2xl border outline-none focus:ring-2 focus:ring-blue-500">
            <?php else: ?>
                <label class="text-xs font-bold text-slate-400 uppercase">Full Name</label>
                <input type="text" name="full_name" value="<?php echo $data['full_name']; ?>" class="w-full p-4 bg-slate-50 rounded-2xl border outline-none focus:ring-2 focus:ring-emerald-500">
                <label class="text-xs font-bold text-slate-400 uppercase">Email Address</label>
                <input type="email" name="email" value="<?php echo $data['email']; ?>" class="w-full p-4 bg-slate-50 rounded-2xl border outline-none focus:ring-2 focus:ring-emerald-500">
            <?php endif; ?>
            
            <div class="flex space-x-3 pt-4">
                <a href="admin_dashboard.php" class="flex-1 text-center py-4 bg-slate-100 text-slate-600 rounded-2xl font-bold">Cancel</a>
                <button type="submit" class="flex-1 py-4 bg-slate-900 text-white rounded-2xl font-bold shadow-lg">Save Changes</button>
            </div>
        </form>
    </div>
</body>
</html>