<?php 
include 'config.php'; 
checkRole('lecturer'); 

// --- DELETE LOGIC ---
if (isset($_GET['delete_id'])) {
    $id = mysqli_real_escape_string($conn, $_GET['delete_id']);
    // First, get the file path to delete the actual file from the folder
    $file_res = mysqli_query($conn, "SELECT file_path FROM materials WHERE id = '$id'");
    $file_data = mysqli_fetch_assoc($file_res);
    
    if ($file_data && file_exists($file_data['file_path'])) {
        unlink($file_data['file_path']); 
    }
    
    mysqli_query($conn, "DELETE FROM materials WHERE id = '$id'");
    header("Location: upload_materials.php?status=success&msg=Removed");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <title>Upload Materials | SmartLMS</title>
</head>
<body class="bg-[#f8fafc] p-6 lg:p-10">

    <div class="max-w-4xl mx-auto">
        <header class="mb-10 text-center">
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight italic uppercase">Resource <span class="text-indigo-600">Manager</span></h1>
        </header>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="lg:col-span-1">
                <div class="bg-white rounded-[2rem] p-6 border border-slate-200 shadow-xl shadow-slate-200/50">
                    <h3 class="text-xs font-black uppercase text-indigo-600 mb-6 italic">Upload New</h3>
                    <form action="process_upload.php" method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="text" name="title" required placeholder="Material Title" class="w-full p-3 bg-slate-50 border rounded-xl text-sm">
                        
                        <select name="type" required class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-600">
                            <option value="pdf">PDF Document</option>
                            <option value="video">Video Lecture</option>
                        </select>

                        <select name="course_id" required class="w-full p-3 bg-slate-50 border rounded-xl text-sm text-slate-600">
                            <option value="">Select Course...</option>
                            <?php 
                            $courses = mysqli_query($conn, "SELECT * FROM courses");
                            while($c = mysqli_fetch_assoc($courses)) echo "<option value='{$c['id']}'>{$c['title']}</option>";
                            ?>
                        </select>

                        <div class="relative w-full p-4 border-2 border-dashed border-slate-200 rounded-2xl flex flex-col items-center bg-slate-50/50">
                            <input type="file" name="resource_file" required class="absolute inset-0 opacity-0 cursor-pointer">
                            <i class="fa-solid fa-cloud-arrow-up text-xl text-slate-300 mb-1"></i>
                            <p class="text-[9px] font-bold text-slate-500 uppercase">Attach File</p>
                        </div>

                        <button type="submit" class="w-full py-4 bg-slate-900 text-white rounded-xl font-black text-xs uppercase shadow-lg hover:bg-indigo-600 transition-all">Publish</button>
                    </form>
                </div>
            </div>

            <div class="lg:col-span-2">
                <div class="bg-white rounded-[2rem] border border-slate-200 shadow-sm overflow-hidden">
                    <table class="w-full text-left">
                        <thead class="bg-slate-50 border-b">
                            <tr>
                                <th class="p-4 text-[10px] font-black uppercase text-slate-400">File Info</th>
                                <th class="p-4 text-[10px] font-black uppercase text-slate-400 text-center">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-50">
                            <?php 
                            $materials = mysqli_query($conn, "SELECT * FROM materials ORDER BY id DESC");
                            while($row = mysqli_fetch_assoc($materials)): 
                            ?>
                            <tr class="hover:bg-slate-50/50 transition-all">
                                <td class="p-4">
                                    <p class="text-sm font-bold text-slate-800"><?php echo htmlspecialchars($row['title']); ?></p>
                                    <span class="text-[9px] font-black uppercase <?php echo $row['type'] == 'pdf' ? 'text-red-500' : 'text-blue-500'; ?>"><?php echo $row['type']; ?></span>
                                </td>
                                <td class="p-4">
                                    <div class="flex justify-center space-x-2">
                                        <a href="edit_material.php?id=<?php echo $row['id']; ?>" class="w-8 h-8 rounded-lg bg-indigo-50 text-indigo-600 flex items-center justify-center hover:bg-indigo-600 hover:text-white transition-all">
                                            <i class="fa-solid fa-pen-to-square text-xs"></i>
                                        </a>
                                        <a href="upload_materials.php?delete_id=<?php echo $row['id']; ?>" onclick="return confirm('Delete this resource?')" class="w-8 h-8 rounded-lg bg-red-50 text-red-500 flex items-center justify-center hover:bg-red-600 hover:text-white transition-all">
                                            <i class="fa-solid fa-trash-can text-xs"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</body>
</html>