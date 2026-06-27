<?php
session_start();
require_once '../../config/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: ../auth/login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$successMessage = "";
$errorMessage = "";

// =========================
// FETCH USER DATA (WITH SETTINGS)
// =========================
$stmt = $conn->prepare("
    SELECT users.*, user_settings.monthly_income 
    FROM users 
    LEFT JOIN user_settings ON user_settings.user_id = users.id 
    WHERE users.id = ?
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();

// =========================
// EMAIL VALIDATION FUNCTION
// =========================
function isValidEmail(string $email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// =========================
// HANDLE FORM
// =========================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {

    $name = trim($_POST['name']);
    $email = trim($_POST['email']);
    $monthly_income = floatval($_POST['monthly_income'] ?? 0);

    // ================= EMAIL VALIDATION =================
    if (!isValidEmail($email)) {
        $errorMessage = "Invalid email format!";
    } else {

        // Check duplicate email
        $check = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $check->bind_param("si", $email, $user_id);
        $check->execute();
        $check->store_result();

        if ($check->num_rows > 0) {
            $errorMessage = "Email already exists!";
        } else {

            // ================= PROFILE IMAGE =================
            $profile_pic = $user['profile_pic'];

            if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] === 0) {

                $target_dir = __DIR__ . "/../../assets/uploads/";

                if (!is_dir($target_dir)) {
                    mkdir($target_dir, 0777, true);
                }

                $file = $_FILES['profile_pic'];

                $allowed = ['image/jpeg', 'image/png'];

                $fileType = mime_content_type($file['tmp_name']);

                if (!in_array($fileType, $allowed)) {
                    $errorMessage = "Only JPG & PNG allowed!";
                } elseif ($file['size'] > 2 * 1024 * 1024) {
                    $errorMessage = "Max size 2MB!";
                } else {

                    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                    $filename = time() . "_" . uniqid() . "." . $ext;

                    if (move_uploaded_file($file['tmp_name'], $target_dir . $filename)) {
                        $profile_pic = $filename;
                    } else {
                        $errorMessage = "Failed to upload image.";
                    }
                }
            }

            if (empty($errorMessage)) {
                // ================= PASSWORD CHANGE =================
                if (!empty($_POST['current_password'])) {

                    $current = $_POST['current_password'];
                    $new = $_POST['new_password'];
                    $confirm = $_POST['confirm_password'];

                    if (!password_verify($current, $user['password'])) {
                        $errorMessage = "Current password incorrect!";
                    } elseif ($new !== $confirm) {
                        $errorMessage = "New passwords do not match!";
                    } elseif (!preg_match('/^(?=.*[a-z])(?=.*[A-Z])(?=.*[0-9]).{8,}$/', $new)) {
                        $errorMessage = "Password must be 8+ chars, include uppercase, lowercase and number!";
                    } else {
                        $hashed = password_hash($new, PASSWORD_DEFAULT);

                        $update = $conn->prepare("
                            UPDATE users 
                            SET name=?, email=?, password=?, profile_pic=? 
                            WHERE id=?
                        ");
                        $update->bind_param("ssssi", $name, $email, $hashed, $profile_pic, $user_id);
                        $update->execute();

                        // SYNC MONTHLY INCOME
                        $incomeCheck = $conn->prepare("SELECT id FROM user_settings WHERE user_id=?");
                        $incomeCheck->bind_param("i", $user_id);
                        $incomeCheck->execute();
                        $exists = $incomeCheck->get_result()->num_rows;

                        if ($exists > 0) {
                            $incomeUpdate = $conn->prepare("UPDATE user_settings SET monthly_income=? WHERE user_id=?");
                            $incomeUpdate->bind_param("di", $monthly_income, $user_id);
                            $incomeUpdate->execute();
                        } else {
                            $incomeInsert = $conn->prepare("INSERT INTO user_settings(user_id, monthly_income) VALUES (?,?)");
                            $incomeInsert->bind_param("id", $user_id, $monthly_income);
                            $incomeInsert->execute();
                        }

                        $successMessage = "Profile and Password updated successfully!";
                        $_SESSION['user_name'] = $name;
                        $_SESSION['profile_pic'] = $profile_pic;
                    }


                } else {
                    // ================= NORMAL UPDATE =================
                    $update = $conn->prepare("
                        UPDATE users 
                        SET name=?, email=?, profile_pic=? 
                        WHERE id=?
                    ");
                    $update->bind_param("sssi", $name, $email, $profile_pic, $user_id);
                    $update->execute();

                    // SYNC MONTHLY INCOME
                    $incomeCheck = $conn->prepare("SELECT id FROM user_settings WHERE user_id=?");
                    $incomeCheck->bind_param("i", $user_id);
                    $incomeCheck->execute();
                    $exists = $incomeCheck->get_result()->num_rows;

                    if ($exists > 0) {
                        $incomeUpdate = $conn->prepare("UPDATE user_settings SET monthly_income=? WHERE user_id=?");
                        $incomeUpdate->bind_param("di", $monthly_income, $user_id);
                        $incomeUpdate->execute();
                    } else {
                        $incomeInsert = $conn->prepare("INSERT INTO user_settings(user_id, monthly_income) VALUES (?,?)");
                        $incomeInsert->bind_param("id", $user_id, $monthly_income);
                        $incomeInsert->execute();
                    }

                    $successMessage = "Profile updated successfully!";
                    $_SESSION['user_name'] = $name;
                    $_SESSION['profile_pic'] = $profile_pic;
                }

                
                $stmt->execute();
                $user = $stmt->get_result()->fetch_assoc();
            }
        }
    }
}

// =========================
// DELETE ACCOUNT (FULL CASCADE)
// =========================

$pageTitle = "Account Settings";
require_once '../../includes/header.php';
require_once '../../includes/sidebar.php';
?>

<style>
.input-with-toggle {
    position: relative;
    display: flex;
    align-items: center;
}
.password-toggle {
    position: absolute;
    right: 1rem;
    background: none;
    border: none;
    cursor: pointer;
    color: var(--text-dim);
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    transition: var(--transition);
}
.password-toggle:hover {
    color: var(--text-main);
}
.password-toggle svg {
    width: 20px;
    height: 20px;
}
</style>

<div style="margin-bottom: 2rem;">
    <h1 style="font-size: 1.75rem; margin-bottom: 0.5rem;">Profile Settings</h1>
    <p class="text-muted" style="font-size: 0.875rem;">Manage your personal information, security preferences, and account details.</p>
</div>

<?php if ($successMessage): ?>
    <div class="alert-card" style="background: rgba(16, 185, 129, 0.05); border-color: rgba(16, 185, 129, 0.2); margin-bottom: 1.5rem; color: var(--success); font-weight: 500; display: flex; align-items: center; gap: 0.75rem;">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        <?= htmlspecialchars($successMessage) ?>
    </div>
<?php endif; ?>

<?php if ($errorMessage): ?>
    <div class="alert-card" style="background: rgba(239, 68, 68, 0.05); border-color: rgba(239, 68, 68, 0.2); margin-bottom: 1.5rem; color: var(--danger); font-weight: 500; display: flex; align-items: center; gap: 0.75rem;">
        <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
        <?= htmlspecialchars($errorMessage) ?>
    </div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" novalidate>
    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 2rem;">
        
        <!-- LEFT COLUMN: INFO & SECURITY -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            
            <!-- BASIC INFO -->
            <section class="card">
                <div class="card-header">
                    <h3 class="card-title">Personal Information</h3>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Full Name</label>
                    <input type="text" name="name" value="<?= htmlspecialchars($user['name']) ?>" class="form-control" placeholder="Enter your full name">
                </div>
                
                <div class="form-group">
                    <label class="form-label">Email Address</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" class="form-control" placeholder="email@example.com">
                    <small class="text-dim" style="display: block; margin-top: 0.5rem; font-size: 0.75rem;">Used for login and notifications.</small>
                </div>

                <div class="form-group">
                    <label class="form-label">Monthly Income (RM)</label>
                    <input type="number" step="0.01" min="0" name="monthly_income" value="<?= htmlspecialchars($user['monthly_income'] ?? 0) ?>" class="form-control" placeholder="Enter monthly income">
                    <small class="text-dim" style="display:block; margin-top:6px; font-size: 0.75rem;">This amount will be used as your base monthly income on the dashboard.</small>
                </div>
            </section>

            <!-- SECURITY / PASSWORD -->
            <section class="card">
                <div class="card-header">
                    <h3 class="card-title">Password Management</h3>
                </div>
                
                <div style="background: rgba(255, 255, 255, 0.02); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border); margin-bottom: 1.5rem;">
                    <p class="text-dim" style="font-size: 0.8125rem; margin: 0;">Leave password fields empty if you don't want to change and keep current password.</p>
                </div>

                <div class="form-group">
                    <label class="form-label">Current Password</label>
                    <div class="input-with-toggle">
                        <input type="password" name="current_password" class="form-control" placeholder="••••••••" id="current_pwd">
                        <button type="button" class="password-toggle" onclick="togglePassword('current_pwd')">
                            <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <div class="form-group">
                        <label class="form-label">New Password</label>
                        <div class="input-with-toggle">
                            <input type="password" name="new_password" class="form-control" placeholder="••••••••" id="new_pwd">
                            <button type="button" class="password-toggle" onclick="togglePassword('new_pwd')">
                                <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Confirm New Password</label>
                        <div class="input-with-toggle">
                            <input type="password" name="confirm_password" class="form-control" placeholder="••••••••" id="confirm_pwd">
                            <button type="button" class="password-toggle" onclick="togglePassword('confirm_pwd')">
                                <svg class="eye-open" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="text-dim" style="font-size: 0.75rem; display: flex; gap: 0.5rem; align-items: flex-start; background: rgba(99, 102, 241, 0.05); padding: 0.75rem; border-radius: 8px; border-left: 3px solid var(--primary);">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-top: 2px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>
                    <span>Must be at least 8 characters, including uppercase, lowercase and number.</span>
                </div>
            </section>
            
            <div style="display: flex; justify-content: flex-end;">
                <button type="submit" name="update_profile" class="btn btn-primary" style="padding: 0.75rem 2rem;">Save Changes</button>
            </div>

        </div>

        <!-- RIGHT COLUMN: PIC & STATUS -->
        <div style="display: flex; flex-direction: column; gap: 2rem;">
            
            <!-- PROFILE PICTURE -->
            <section class="card" style="text-align: center;">
                <div class="card-header" style="justify-content: center;">
                    <h3 class="card-title">Profile Avatar</h3>
                </div>
                
                <div style="margin-bottom: 1.5rem;">
                    <div style="width: 120px; height: 120px; border-radius: 20px; background: var(--bg-dark-alt); margin: 0 auto 1.5rem; border: 2px solid var(--border); overflow: hidden; position: relative;">
                        <?php 
                        $pic_path = "../../assets/uploads/" . ($user['profile_pic'] ? $user['profile_pic'] : 'missing');
                        // Use physical path check to see if we should show the image
                        if ($user['profile_pic'] && file_exists(__DIR__ . "/../../assets/uploads/" . $user['profile_pic'])): 
                        ?>
                            <img src="../../assets/uploads/<?= htmlspecialchars($user['profile_pic']) ?>" alt="Profile" style="width: 100%; height: 100%; object-fit: cover;">
                        <?php else: ?>
                            <div style="width: 100%; height: 100%; display: grid; place-items: center; background: var(--primary); color: white; font-size: 3rem; font-weight: 700;">
                                <?= strtoupper(substr($user['name'], 0, 1)) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <label class="btn btn-secondary btn-sm" style="width: 100%; cursor: pointer;">
                        Choose New Photo
                        <input type="file" name="profile_pic" style="display: none;">
                    </label>
                    <p class="text-dim" style="font-size: 0.75rem; margin-top: 0.75rem;">JPG or PNG, max 2MB</p>
                </div>
            </section>


        </div>
    </div>
</form>


<script>
    function togglePassword(inputId) {
        const input = document.getElementById(inputId);
        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', type);
        
        // Optional: Toggle icon (Eye vs Eye-Off)
        // For simplicity, we keep the eye but you could switch the SVG path here
    }

</script>

<?php require_once '../../includes/footer.php'; ?>
