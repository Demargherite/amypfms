<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = $pageTitle ?? 'AMYFI Finance';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($pageTitle) ?> - AMYFI</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Global CSS -->
    <link rel="stylesheet" href="../../assets/css/style.css?v=1.5">
</head>

<body>

<div class="amyfi-app">
    <!-- Note: Sidebar is included individually in application pages -->

    <div class="amyfi-main">
        <header style="padding: 1.25rem 2rem; background: rgba(15, 23, 42, 0.4); border-bottom: 1px solid var(--border); backdrop-filter: blur(8px); position: sticky; top: 0; z-index: 100;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h2 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: var(--text-main);"><?= htmlspecialchars($pageTitle) ?></h2>
                
                <!-- Secondary Header Actions -->
                <div style="display: flex; gap: 1rem; align-items: center;">
                    <div class="text-dim" style="font-size: 0.875rem; font-weight: 500;">
                        <?= date('l, d M Y') ?>
                    </div>
                </div>
            </div>
        </header>

        <div class="amyfi-main-content">
