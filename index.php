<?php
/**
 * Laragon Hub - Modern Development Cockpit
 * Portability Update: Removed hardcoded paths in favor of config.php and auto-detection.
 */

$configPath = __DIR__ . '/config.php';
$defaults = [
    'www_root' => 'C:\\laragon\\www',
    'vscode_exe' => '',
    'ngrok_exe' => '',
    'ngrok_config' => '',
    'ngrok_url' => '',
];
$cfg = $defaults;
if (file_exists($configPath)) {
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $cfg = array_merge($defaults, $loaded);
    }
}
$isConfigured = file_exists($configPath);

function jsonResponse(array $data): void {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function normalizeWinPath($path) {
    $path = trim((string) $path);
    return $path === '' ? '' : str_replace('/', '\\', $path);
}

function autoDetectPath(array $candidates) {
    foreach ($candidates as $candidate) {
        $candidate = trim($candidate);
        if ($candidate === '') continue;
        if (is_file($candidate)) return $candidate;
    }
    foreach (['where code', 'where ngrok'] as $probe) {
        $out = [];
        @exec($probe . ' 2>nul', $out);
        if (!empty($out)) {
            foreach ($out as $line) {
                $line = trim($line);
                if ($line !== '' && is_file($line)) return $line;
            }
        }
    }
    return '';
}

function buildConfigPayload(array $source): array {
    return [
        'www_root' => normalizeWinPath($source['www_root'] ?? 'C:\\laragon\\www'),
        'vscode_exe' => normalizeWinPath($source['vscode_exe'] ?? ''),
        'ngrok_exe' => normalizeWinPath($source['ngrok_exe'] ?? ''),
        'ngrok_config' => normalizeWinPath($source['ngrok_config'] ?? ''),
        'ngrok_url' => trim((string) ($source['ngrok_url'] ?? '')),
    ];
}

function saveConfigFile(string $configPath, array $cfg): array {
    $dir = dirname($configPath);
    if (!is_dir($dir) || !is_writable($dir)) {
        return [false, 'Folder repo tidak bisa ditulis. Pindahkan repo ke lokasi yang writable.'];
    }
    $content = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    $tempPath = $configPath . '.tmp';
    if (file_put_contents($tempPath, $content, LOCK_EX) === false) {
        return [false, 'Gagal menulis config sementara.'];
    }
    if (!@rename($tempPath, $configPath)) {
        @unlink($tempPath);
        return [false, 'Gagal mengganti config.php.'];
    }
    return [true, ''];
}

function detectRuntimeConfig(array $cfg): array {
    if (($cfg['vscode_exe'] ?? '') === '') {
        $cfg['vscode_exe'] = autoDetectPath([
            getenv('ProgramFiles') ? getenv('ProgramFiles') . '\\Microsoft VS Code\\Code.exe' : '',
            getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '\\Programs\\Microsoft VS Code\\Code.exe' : '',
            'C:\\Users\\' . get_current_user() . '\\AppData\\Local\\Programs\\Microsoft VS Code\\Code.exe',
        ]);
    }
    if (($cfg['ngrok_exe'] ?? '') === '') {
        $cfg['ngrok_exe'] = autoDetectPath([
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\AppData\\Local\\ngrok\\ngrok.exe' : '',
            getenv('USERPROFILE') ? getenv('USERPROFILE') . '\\AppData\\Roaming\\ngrok-v3-stable-windows-amd64\\ngrok.exe' : '',
        ]);
    }
    if (($cfg['ngrok_config'] ?? '') === '') {
        $possible = getenv('LOCALAPPDATA') ? getenv('LOCALAPPDATA') . '\\ngrok\\ngrok.yml' : '';
        if ($possible !== '' && file_exists($possible)) {
            $cfg['ngrok_config'] = $possible;
        }
    }
    return $cfg;
}

if (isset($_POST['save_config'])) {
    $newCfg = buildConfigPayload($_POST);
    [$ok, $error] = saveConfigFile($configPath, $newCfg);
    jsonResponse($ok ? ['success' => true] : ['success' => false, 'message' => $error]);
}

$cfg = $isConfigured ? $cfg : detectRuntimeConfig($cfg);
$url = $cfg['ngrok_url'];
$directory = $cfg['www_root'];
if ($directory === '') $directory = $defaults['www_root'];

// Fungsi untuk mendapatkan IP asli pengguna
function getRealUserIP() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($ips[0]);
    }
    return $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'];
}

$user_ip = getRealUserIP();
$allowed_ips = ['127.0.0.1', '::1', 'localhost'];
$is_local_ip = (filter_var($user_ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false);

if (!$is_local_ip && !in_array($user_ip, $allowed_ips)) {
    header('Location: restricted.php');
    exit;
}

// Handle Request Buka VS Code via AJAX
if (isset($_POST['open_code'])) {
    header('Content-Type: application/json');
    $folder_path = $_POST['open_code'];

    if (is_dir($folder_path)) {
        $path = str_replace('/', '\\', $folder_path);
        $vscode = $cfg['vscode_exe'];
        if (empty($vscode) || !file_exists($vscode)) {
            jsonResponse(['success' => false, 'message' => 'Path VS Code tidak valid. Atur di Settings.']);
        }
        $userProfile = getenv('USERPROFILE') ?: '';
        $command = ($userProfile !== '' ? 'set "USERPROFILE=' . $userProfile . '"&& ' : '') . 'start "" "' . $vscode . '" "' . $path . '"';
        pclose(popen($command, "r"));
        jsonResponse(['success' => true, 'message' => 'VS Code berhasil dibuka']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Direktori tidak ditemukan']);
    }
    exit;
}

// Handle Get Active Tunnel
if (isset($_POST['get_active_tunnel'])) {
    $stateFile = __DIR__ . DIRECTORY_SEPARATOR . '.ngrok_active.json';
    if (file_exists($stateFile)) {
        jsonResponse(json_decode((string) file_get_contents($stateFile), true) ?: ['active' => false]);
    }
    jsonResponse(['active' => false]);
}

// Handle Kill Tunnel
if (isset($_POST['kill_tunnel'])) {
    header('Content-Type: application/json');
    exec('taskkill /F /IM ngrok.exe 2>&1');
    exec('taskkill /F /FI "WINDOWTITLE eq ngrok_tunnel*" 2>&1');
    $stateFile = __DIR__ . DIRECTORY_SEPARATOR . '.ngrok_active.json';
    if (file_exists($stateFile)) unlink($stateFile);
    echo json_encode(['success' => true, 'message' => 'Ngrok process terminated']);
    exit;
}

// Handle Share Project via Ngrok
if (isset($_POST['share_project'])) {
    header('Content-Type: application/json');
    $project = trim($_POST['share_project'] ?? '');
    $ngrokUrl = trim($_POST['ngrok_url'] ?? '');

    if (empty($project) || empty($ngrokUrl)) {
        echo json_encode(['success' => false, 'message' => 'Data tidak lengkap']);
        exit;
    }

    $parsed = parse_url($ngrokUrl);
    if (!isset($parsed['host'])) {
        echo json_encode(['success' => false, 'message' => 'URL Ngrok tidak valid']);
        exit;
    }

    $ngrokExe = $cfg['ngrok_exe'];
    $ngrokCfg = $cfg['ngrok_config'];
    if (empty($ngrokExe) || !file_exists($ngrokExe)) {
        echo json_encode(['success' => false, 'message' => 'Path Ngrok tidak valid. Atur di Settings.']);
        exit;
    }

    $ngrokHost = $parsed['host'];
    $projectHost = $project . '.test';

    exec('taskkill /F /IM ngrok.exe 2>&1');
    sleep(1);

    $command = 'start "ngrok_tunnel" cmd /c "' . $ngrokExe . ' http 80 ' . (!empty($ngrokCfg) ? '--config=' . $ngrokCfg : '') . ' --host-header=' . $projectHost . ' --url ' . $ngrokHost . '"';
    pclose(popen($command, "r"));

    $stateFile = __DIR__ . DIRECTORY_SEPARATOR . '.ngrok_active.json';
    file_put_contents($stateFile, json_encode(['active' => true, 'project' => $project, 'timestamp' => time()]));

    echo json_encode(['success' => true]);
    exit;
}

// Handle Delete Project
if (isset($_POST['delete_project'])) {
    header('Content-Type: application/json');
    $project = basename($_POST['delete_project'] ?? '');
    $path = $directory . DIRECTORY_SEPARATOR . $project;
    $realBase = realpath($directory);
    $realPath = realpath($path);

    if (!$realPath || !is_dir($realPath) || strpos($realPath, $realBase) !== 0) {
        echo json_encode(['success' => false, 'message' => 'Proyek tidak valid']);
        exit;
    }

    pclose(popen("cmd /c rmdir /s /q \"$realPath\"", "r"));
    sleep(1);

    $project_dirs = [];
    if (is_dir($directory)) {
        foreach (array_diff(scandir($directory), ['.', '..']) as $item) {
            if (is_dir($directory . DIRECTORY_SEPARATOR . $item)) $project_dirs[] = $item;
        }
    }
    echo json_encode(['success' => true, 'message' => 'Proyek dihapus', 'project_count' => count($project_dirs)]);
    exit;
}

if (isset($_POST['get_project_count'])) {
    header('Content-Type: application/json');
    $count = 0;
    if (is_dir($directory)) {
        foreach (array_diff(scandir($directory), ['.', '..']) as $item) {
            if (is_dir($directory . DIRECTORY_SEPARATOR . $item)) $count++;
        }
    }
    echo json_encode(['count' => $count]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laragon Hub &mdash; Local Development Console</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@500;700&family=Inter:wght@400;500;600&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <style>
        :root { --ink: #0e1117; --surface: #161a22; --surface-soft: #1c212b; --line: #2a3140; --text: #e8ebf1; --muted: #828ca0; --faint: #4d5566; --amber: #f4a623; --cyan: #3ad8c4; --danger: #f0556b; }
        body { font-family: 'Inter', sans-serif; background-color: var(--ink); background-image: radial-gradient(rgba(255,255,255,0.045) 1px, transparent 1px); background-size: 24px 24px; }
        .font-display { font-family: 'Space Grotesk', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .config-input { width: 100%; background: var(--surface-soft); border: 1px solid var(--line); border-radius: 0.375rem; padding: 0.5rem 0.75rem; font-size: 0.875rem; color: var(--text); outline: none; }
        .config-input:focus { border-color: var(--amber); }
    </style>
</head>

<?php if (!$isConfigured): ?>
<body class="min-h-screen flex items-center justify-center p-6 text-[var(--text)]">
    <div class="max-w-xl w-full bg-[var(--surface)] border border-[var(--line)] rounded-2xl p-8 shadow-2xl">
        <div class="flex items-center gap-4 mb-8">
            <div class="w-12 h-12 rounded-xl bg-[var(--amber)]/10 border border-[var(--amber)]/20 flex items-center justify-center text-[var(--amber)] text-2xl">
                <i class="fas fa-sliders"></i>
            </div>
            <div>
                <h1 class="font-display text-2xl font-bold">Setup Laragon Hub</h1>
                <p class="text-sm text-[var(--muted)]">Konfigurasi lingkungan lokal Anda</p>
            </div>
        </div>

        <form id="setupForm" class="space-y-5">
            <input type="hidden" name="save_config" value="1">
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-[var(--faint)] mb-2">Laragon WWW Root</label>
                <input type="text" name="www_root" value="<?php echo htmlspecialchars($cfg['www_root']); ?>" class="config-input" placeholder="C:\laragon\www">
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-[var(--faint)] mb-2">VS Code Executable Path</label>
                <input type="text" name="vscode_exe" value="<?php echo htmlspecialchars($cfg['vscode_exe']); ?>" class="config-input" placeholder="C:\...\Code.exe">
                <p class="text-[10px] text-[var(--faint)] mt-1">Digunakan untuk tombol "Open in VS Code".</p>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[var(--faint)] mb-2">Ngrok Executable</label>
                    <input type="text" name="ngrok_exe" value="<?php echo htmlspecialchars($cfg['ngrok_exe']); ?>" class="config-input" placeholder="C:\...\ngrok.exe">
                </div>
                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider text-[var(--faint)] mb-2">Ngrok Config (Optional)</label>
                    <input type="text" name="ngrok_config" value="<?php echo htmlspecialchars($cfg['ngrok_config']); ?>" class="config-input" placeholder="C:\...\ngrok.yml">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold uppercase tracking-wider text-[var(--faint)] mb-2">Default Ngrok URL (Optional)</label>
                <input type="text" name="ngrok_url" value="<?php echo htmlspecialchars($cfg['ngrok_url']); ?>" class="config-input" placeholder="https://xxx.ngrok-free.dev">
            </div>

            <button type="submit" class="w-full bg-[var(--amber)] hover:bg-[#ffb43d] text-black font-bold py-3 rounded-lg transition-all transform active:scale-[0.98] mt-4">
                Simpan & Mulai Dashboard
            </button>
        </form>
    </div>

    <script>
        document.getElementById('setupForm').onsubmit = function(e) {
            e.preventDefault();
            const btn = e.target.querySelector('button');
            btn.disabled = true;
            btn.innerText = 'Menyimpan...';

            fetch(window.location.pathname, { method: 'POST', body: new FormData(this) })
            .then(async r => ({ ok: r.ok, data: await r.json() }))
            .then(({ data }) => {
                if (data.success) window.location.reload();
                else alert(data.message || 'Gagal menyimpan config');
            })
            .catch(() => alert('Respons server tidak valid'))
            .finally(() => { btn.disabled = false; btn.innerText = 'Simpan & Mulai Dashboard'; });
        };
    </script>
</body>
<?php else: ?>

<body class="min-h-screen text-[var(--text)] antialiased selection:bg-[var(--amber)] selection:text-black">
    <header class="border-b border-[var(--line)] bg-[var(--ink)]/95 backdrop-blur sticky top-0 z-30">
        <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-lg border border-[var(--line)] bg-[var(--surface)] flex items-center justify-center text-[var(--amber)]">
                    <i class="fas fa-circle-nodes"></i>
                </div>
                <div>
                    <h1 class="font-display text-lg font-bold text-[var(--text)] tracking-tight leading-none">Laragon Hub</h1>
                    <p class="text-[11px] text-[var(--muted)] mt-0.5">Local development console</p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <div class="hidden sm:flex items-center divide-x divide-[var(--line)] border border-[var(--line)] rounded-lg overflow-hidden text-[11px] bg-[var(--surface)]">
                    <span class="px-3 py-1.5 text-[var(--muted)]">PHP <span class="font-mono text-[var(--text)]"><?php echo phpversion(); ?></span></span>
                    <button onclick="showPhpInfo()" class="px-3 py-1.5 text-[var(--amber)] font-semibold hover:bg-[var(--surface-soft)] transition-colors cursor-pointer">PHP Info</button>
                </div>
                <button onclick="openSettings()" class="w-9 h-9 flex items-center justify-center rounded-lg border border-[var(--line)] bg-[var(--surface)] text-[var(--muted)] hover:text-[var(--amber)] transition-colors cursor-pointer">
                    <i class="fas fa-cog"></i>
                </button>
            </div>
        </div>
    </header>

    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
        <div class="rounded-xl border border-[var(--line)] bg-[var(--surface)] flex flex-col md:flex-row divide-y md:divide-y-0 md:divide-x divide-[var(--line)] overflow-hidden mb-2">
            <div class="flex-1 flex items-center gap-3 px-5 py-4">
                <span class="font-mono text-[var(--cyan)] text-sm">&gt;_</span>
                <input type="text" id="searchInput" placeholder="filter projects..." class="flex-1 bg-transparent outline-none font-mono text-sm text-[var(--text)] placeholder:text-[var(--faint)]">
            </div>
            <div class="flex-1 flex items-center gap-3 px-5 py-4">
                <i class="fas fa-plug text-[var(--amber)] text-sm"></i>
                <input type="text" id="ngrokUrl" placeholder="https://xxxx-xx-xxx.ngrok-free.dev" value="<?php echo htmlspecialchars($url); ?>" class="flex-1 bg-transparent outline-none font-mono text-sm text-[var(--text)] placeholder:text-[var(--faint)]">
            </div>
        </div>
        <p class="text-[11px] text-[var(--faint)] px-1 mb-4">Tunnel target ini dipakai oleh tombol share di setiap kartu project.</p>

        <div id="activeTunnelBanner" class="hidden rounded-xl border border-[var(--amber)]/30 bg-[var(--amber)]/5 px-5 py-3 mb-6 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <i class="fas fa-share-nodes text-[var(--amber)]"></i>
                <div>
                    <p class="text-xs font-semibold text-[var(--text)]">Active tunnel: <span id="activeTunnelProject" class="font-mono text-[var(--amber)]"></span></p>
                    <p class="text-[10px] text-[var(--muted)] mt-0.5">Project ini sedang di-share via ngrok</p>
                </div>
            </div>
            <button onclick="killTunnel()" class="px-3 py-1.5 text-xs rounded-md bg-[var(--danger)] text-white font-semibold hover:bg-[var(--danger)]/80 transition-colors cursor-pointer">Stop tunnel</button>
        </div>

        <div class="flex items-center justify-between border-b border-[var(--line)] pb-4 mb-6">
            <h2 class="font-display text-base font-bold text-[var(--text)]">Workspace projects</h2>
            <?php
            $project_dirs = [];
            if (is_dir($directory)) {
                foreach (array_diff(scandir($directory), ['.', '..']) as $item) {
                    if (is_dir($directory . DIRECTORY_SEPARATOR . $item)) $project_dirs[] = $item;
                }
            }
            ?>
            <span class="font-mono text-[11px] text-[var(--muted)] border border-[var(--line)] rounded-md px-2 py-1"><?php echo count($project_dirs); ?> mounted</span>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4" id="projectsContainer">
            <?php if (!is_dir($directory)): ?>
                <div class="col-span-full bg-[#2a1418] border border-[var(--danger)]/30 rounded-xl p-6 text-center">
                    <i class="fas fa-triangle-exclamation text-[var(--danger)] text-3xl mb-2"></i>
                    <p class="text-[#f5b8c2] text-sm font-bold">Direktori tidak ditemukan</p>
                    <p class="text-[var(--danger)] text-xs mt-0.5">Pastikan jalur ini valid di Settings: <span class="font-mono bg-black/20 px-1 rounded"><?php echo htmlspecialchars($directory); ?></span></p>
                </div>
            <?php elseif (empty($project_dirs)): ?>
                <div class="col-span-full text-center py-16 bg-[var(--surface)] border border-[var(--line)] border-dashed rounded-xl">
                    <i class="fas fa-folder-open text-[var(--line)] text-5xl mb-3"></i>
                    <p class="text-[var(--muted)] font-medium text-sm">Belum ada folder proyek terdeteksi di www.</p>
                </div>
            <?php else:
                foreach ($project_dirs as $project):
                    $project_url = 'http://localhost/' . $project;
                    $project_test_url = 'http://' . $project . '.test';
                    $project_path = $directory . DIRECTORY_SEPARATOR . $project;
            ?>
                <div class="project-card group bg-[var(--surface)] border border-[var(--line)] rounded-xl overflow-hidden hover:border-[var(--amber)]/50 transition-colors flex flex-col" data-project-name="<?php echo strtolower($project); ?>">
                    <div class="p-5 flex-1">
                        <div class="flex items-center gap-2 mb-2">
                            <span class="w-2 h-2 rounded-full bg-[var(--cyan)] shadow-[0_0_8px_rgba(58,216,196,0.6)]"></span>
                            <span class="text-[10px] text-[var(--faint)] uppercase tracking-wider">auto vhost</span>
                        </div>
                        <h3 class="font-mono text-[15px] font-semibold text-[var(--text)] truncate"><?php echo htmlspecialchars($project . '.test'); ?></h3>
                        <p class="text-xs text-[var(--muted)] mt-1 truncate capitalize"><i class="far fa-folder text-[var(--faint)] mr-1"></i> <?php echo htmlspecialchars(str_replace('-', ' ', $project)); ?></p>
                    </div>
                    <div class="grid grid-cols-6 gap-1.5 px-4 py-3 border-t border-[var(--line)] bg-[var(--surface-soft)]">
                        <a href="<?php echo $project_url; ?>" target="_blank" title="Localhost" class="flex items-center justify-center h-8 rounded-md border border-[var(--line)] text-[var(--muted)] hover:text-[var(--cyan)] transition-colors text-xs"><i class="fas fa-arrow-up-right-from-square"></i></a>
                        <a href="<?php echo $project_test_url; ?>" target="_blank" title=".test" class="flex items-center justify-center h-8 rounded-md border border-[var(--line)] text-[var(--muted)] hover:text-[var(--amber)] transition-colors text-xs"><i class="fas fa-globe"></i></a>
                        <button onclick="openInVSCode('<?php echo addslashes($project_path); ?>')" title="VS Code" class="flex items-center justify-center h-8 rounded-md border border-[var(--line)] text-[var(--muted)] hover:text-[#3b9ee5] transition-colors text-xs cursor-pointer"><i class="fab fa-microsoft"></i></button>
                        <button onclick="copyUrl('<?php echo addslashes($project_url); ?>')" title="Copy" class="flex items-center justify-center h-8 rounded-md border border-[var(--line)] text-[var(--muted)] hover:text-[var(--text)] transition-colors text-xs cursor-pointer"><i class="far fa-copy"></i></button>
                        <button onclick="shareProject('<?php echo addslashes($project); ?>')" title="Ngrok" class="flex items-center justify-center h-8 rounded-md bg-[var(--amber)] text-black font-semibold hover:bg-[#ffb43d] transition-colors text-xs cursor-pointer"><i class="fas fa-share-nodes"></i></button>
                        <button onclick="confirmDelete('<?php echo addslashes($project); ?>')" title="Hapus" class="flex items-center justify-center h-8 rounded-md border border-[var(--line)] text-[var(--muted)] hover:text-[var(--danger)] transition-colors text-xs cursor-pointer"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </div>

    <!-- Modals -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
        <div class="bg-[var(--surface)] rounded-xl shadow-2xl max-w-xl w-full border border-[var(--line)] overflow-hidden">
            <div class="px-6 py-4 border-b border-[var(--line)] flex items-center justify-between">
                <h2 class="font-display text-sm font-bold uppercase tracking-wider">Dashboard Settings</h2>
                <button onclick="closeSettings()" class="text-[var(--muted)] hover:text-[var(--text)]"><i class="fas fa-xmark"></i></button>
            </div>
            <form id="settingsForm" class="p-6 space-y-4">
                <input type="hidden" name="save_config" value="1">
                <div>
                    <label class="block text-[10px] font-bold text-[var(--faint)] uppercase mb-1">Laragon WWW Root</label>
                    <input type="text" name="www_root" value="<?php echo htmlspecialchars($cfg['www_root']); ?>" class="config-input">
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-[var(--faint)] uppercase mb-1">VS Code Path</label>
                    <input type="text" name="vscode_exe" value="<?php echo htmlspecialchars($cfg['vscode_exe']); ?>" class="config-input">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-[10px] font-bold text-[var(--faint)] uppercase mb-1">Ngrok Exe</label>
                        <input type="text" name="ngrok_exe" value="<?php echo htmlspecialchars($cfg['ngrok_exe']); ?>" class="config-input">
                    </div>
                    <div>
                        <label class="block text-[10px] font-bold text-[var(--faint)] uppercase mb-1">Ngrok Config</label>
                        <input type="text" name="ngrok_config" value="<?php echo htmlspecialchars($cfg['ngrok_config']); ?>" class="config-input">
                    </div>
                </div>
                <div>
                    <label class="block text-[10px] font-bold text-[var(--faint)] uppercase mb-1">Default Ngrok URL</label>
                    <input type="text" name="ngrok_url" value="<?php echo htmlspecialchars($cfg['ngrok_url']); ?>" class="config-input">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="closeSettings()" class="px-4 py-2 text-xs font-bold rounded-md border border-[var(--line)] hover:bg-[var(--surface-soft)]">Batal</button>
                    <button type="submit" class="px-4 py-2 text-xs font-bold rounded-md bg-[var(--amber)] text-black">Simpan Perubahan</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toastContainer" class="fixed bottom-5 right-5 z-50 flex flex-col gap-2 pointer-events-none"></div>

    <script>
        function openSettings() { document.getElementById('settingsModal').classList.remove('hidden'); }
        function closeSettings() { document.getElementById('settingsModal').classList.add('hidden'); }
        
        document.getElementById('settingsForm').onsubmit = function(e) {
            e.preventDefault();
            fetch(window.location.pathname, { method: 'POST', body: new FormData(this) })
            .then(async r => ({ ok: r.ok, data: await r.json() }))
            .then(({ data }) => data.success ? window.location.reload() : alert(data.message || 'Gagal menyimpan config'))
            .catch(() => alert('Respons server tidak valid'));
        };

        function showToast(msg, type = 'success') {
            const container = document.getElementById('toastContainer');
            const toast = document.createElement('div');
            toast.className = `flex items-center gap-3 px-4 py-3 rounded-lg bg-[var(--surface)] border border-[var(--line)] border-l-4 shadow-lg text-xs font-medium pointer-events-auto transition-all duration-300 transform translate-y-2 opacity-0 ${type === 'success' ? 'border-l-[var(--cyan)]' : 'border-l-[var(--danger)]'}`;
            toast.innerHTML = `<i class="fas fa-${type==='success'?'check-circle':'exclamation-circle'} ${type==='success'?'text-[var(--cyan)]':'text-[var(--danger)]'}"></i> ${msg}`;
            container.appendChild(toast);
            setTimeout(() => toast.classList.remove('translate-y-2', 'opacity-0'), 10);
            setTimeout(() => { toast.classList.add('opacity-0', 'translate-y-2'); setTimeout(() => toast.remove(), 300); }, 3000);
        }

        function openInVSCode(path) {
            fetch('', { method: 'POST', body: new URLSearchParams({ open_code: path }) })
            .then(r => r.json()).then(d => d.success ? showToast('VS Code dibuka') : showToast(d.message, 'error'));
        }

        function shareProject(project) {
            const ngrokUrl = document.getElementById('ngrokUrl').value.trim();
            if(!ngrokUrl) return showToast('Masukkan URL Ngrok!', 'error');
            fetch('', { method: 'POST', body: new URLSearchParams({ share_project: project, ngrok_url: ngrokUrl }) })
            .then(r => r.json()).then(d => d.success ? (showToast('Tunnel aktif'), checkActiveTunnel()) : showToast(d.message, 'error'));
        }

        function killTunnel() {
            fetch('', { method: 'POST', body: new URLSearchParams({ kill_tunnel: '1' }) })
            .then(r => r.json()).then(d => (showToast('Tunnel mati'), checkActiveTunnel()));
        }

        function checkActiveTunnel() {
            fetch('', { method: 'POST', body: new URLSearchParams({ get_active_tunnel: '1' }) })
            .then(r => r.json()).then(data => {
                const banner = document.getElementById('activeTunnelBanner');
                if (data.active) {
                    document.getElementById('activeTunnelProject').innerText = data.project;
                    banner.classList.remove('hidden');
                } else banner.classList.add('hidden');
            });
        }

        function copyUrl(url) {
            navigator.clipboard.writeText(url).then(() => showToast('URL disalin'));
        }

        let searchInput = document.getElementById('searchInput');
        searchInput.oninput = function() {
            let term = this.value.toLowerCase();
            document.querySelectorAll('.project-card').forEach(c => {
                c.style.display = c.dataset.projectName.includes(term) ? 'flex' : 'none';
            });
        };

        function confirmDelete(project) {
            if(confirm('Hapus proyek ' + project + ' secara permanen?')) {
                fetch('', { method: 'POST', body: new URLSearchParams({ delete_project: project }) })
                .then(r => r.json()).then(d => {
                    if(d.success) { showToast(d.message); document.querySelector(`[data-project-name="${project.toLowerCase()}"]`).remove(); }
                    else showToast(d.message, 'error');
                });
            }
        }

        checkActiveTunnel();
    </script>
</body>
<?php endif; ?>
</html>