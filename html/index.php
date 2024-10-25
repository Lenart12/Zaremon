<?php

# Show errors
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'conf.php';
require_once 'locale.php';

session_start();

// Password protection
if (isset($PASSWORD) && !isset($_SESSION['auth'])) {
    // Check password if submitted
    $show_wrong_password = false;

    $test_password = $_POST['password'] ?? $_GET['p'] ?? null;

    if ($test_password) {
        if ($test_password == $PASSWORD) {
            $_SESSION['auth'] = true;
            header('Location: index.php');
            exit();
        } else {
            $show_wrong_password = true;
        }
    }
    
    // Show password form if not authenticated
    if (!isset($_SESSION['auth'])) {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>RFmon - <?php echo $S_LOGIN; ?> </title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
            <link rel="icon" href="rfmon.png" type="image/png">
            <link rel="stylesheet" href="style.css">
            <script src="script.js"></script>
        </head>
        <body>
            <h1>
            <img src="rfmon.png" alt="RFmon" style="height: 2em; vertical-align: middle;">
            - <?php echo $S_LOGIN; ?>
            </h1>
            <div class="content">
            <div class="login">
                <h2 class="login-header">
                    <i class="fas fa-sign-in-alt"></i> <?php echo $S_LOGIN; ?>
                </h2>
                <form action="" method="post">
                    <input type="password" name="password" placeholder="<?php echo $S_PASSWORD; ?>" required>
                    <button type="submit"><i class="fas fa-check"></i> <?php echo $S_LOGIN; ?></button>
                </form>
                <?php if ($show_wrong_password): ?>
                <p class="error"><i class="fas fa-exclamation-circle"></i> <?php echo $S_WRONG_PASSWORD; ?></p>
                <?php endif; ?>
                <br>
                <div>
                <i class="fas fa-code"></i> <?php echo $S_SOURCE_CODE; ?>:
                <a href="https://github.com/Lenart12/RFmon">GitHub</a>
                Lenart @ 2024
                </div>
            </div>
            </div>
        </body>
        </html>
        <?php
        exit();
    }
}


$fmt = new IntlDateFormatter($LOCALE, IntlDateFormatter::RELATIVE_LONG, IntlDateFormatter::NONE);

$audio_files = array_diff(scandir($AUDIO_SRC_DIR, SCANDIR_SORT_DESCENDING), array('..', '.'));

$audio_records = array();

foreach ($audio_files as $file) {
    if (preg_match('/^zm_(\d+)_(\d+)\.mp3$/', $file, $matches)) {
        $date = $matches[1];
        $time = $matches[2];

        $datetime = DateTime::createFromFormat('YmdHis', $date . $time, new DateTimeZone('UTC'));
        $datetime->setTimezone(new DateTimeZone($TIMEZONE));

        if ($datetime->getTimestamp() < time() - $RECORD_MAX_AGE) {
            break;
        }

        if ($SHOW_TRANSCRIPTIONS == true) {
            // Transcription file is same file except with .txt extension
            $transcription_file = $AUDIO_SRC_DIR . '/' . str_replace('.mp3', '.txt', $file);
            $transcription = file_exists($transcription_file) ? file_get_contents($transcription_file) : null;
        } 

        $audio_records[] = array(
            'fid' => $date . '_' . $time,
            'datetime' => $datetime,
            'transcription' => $transcription ?? null
        );
    }
}

$audio_records_grouped = array();

# Group records twice:
#   1. By date
#   2. By group of all records that are withing 30 seconds of each other
foreach ($audio_records as $record) {
    $date = $record['datetime']->format('Y-m-d');

    if (!isset($audio_records_grouped[$date])) {
        $audio_records_grouped[$date] = array();
    }

    $grouped = false;

    foreach ($audio_records_grouped[$date] as &$group) {
        $first_record = reset($group);

        if ($first_record['datetime']->getTimestamp() - $record['datetime']->getTimestamp() <= $TX_GROUPING_THRESHOLD) {
            $group = [$record, ...$group];
            $grouped = true;
            break;
        }
    }

    if (!$grouped) {
        $audio_records_grouped[$date][] = array($record);
    }
}

function date_group_name($date) {
    global $fmt;
    global $audio_records_grouped;
    $datestr = ucfirst($fmt->format(new DateTime($date)));
    $count = count($audio_records_grouped[$date]);
    return "$datestr ($count)";

}

function tx_group_name($group) {
    if (count($group) == 1) {
        return $group[0]['datetime']->format('H:i:s');
    }

    $first_record = reset($group);
    $last_record = end($group);

    return $first_record['datetime']->format('H:i:s') . 
    ' - ' . 
    $last_record['datetime']->format('H:i:s') . 
    ' (' . count($group) . ')';
}

$rfmon_sdr_service_active = trim(shell_exec('systemctl is-active rfmon-sdr.service')) == 'active';
$rfmon_watch_service_active = trim(shell_exec('systemctl is-active rfmon-watch.service')) == 'active';

if (isset($NOTIFY_DIR)) {
    foreach (['NOTIFY_DIR', 'NOTIFY_TIMEOUT', 'NOTIFY_FROM', 'NOTIFY_LINK_HOST'] as $var) {
        if (!isset($$var)) {
            echo "$var not set.";
            exit();
        }
    }

    // check if notify directory exists and is writable
    if (!is_dir($NOTIFY_DIR)) {
        echo "Notify directory not found. $NOTIFY_DIR";
        exit();
    }

    if (!is_writable($NOTIFY_DIR)) {
        echo "Notify directory not writable. $NOTIFY_DIR";
        exit();
    }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo "$TITLE"; ?> - RFmon</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/normalize/8.0.1/normalize.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <link rel="icon" href="rfmon.png" type="image/png">
    <link rel="stylesheet" href="style.css">
    <script src="script.js"></script>
</head>
<body>
    <h1>
        <img src="rfmon.png" alt="RFmon" style="height: 2em; vertical-align: middle;">
        - <?php echo $TITLE; ?>
    </h1>
    <div class="content">
        <div class="controls">
            <h2 class="controls-header">
                <i class="fas fa-cogs"></i> <?php echo $S_SETTINGS; ?>
            </h2>
            <div class="controls-content">
                <a href="index.php"><i class="fas fa-sync-alt"></i> <?php echo $S_REFRESH; ?></a>
                <div class="checkbox-group">
                    <input type="checkbox" id="auto-play" checked>
                    <label for="auto-play"><i class="fas fa-play"></i> <?php echo $S_AUTOPLAY; ?></label>
                </div>
                <?php if (isset($NOTIFY_DIR)): ?>
                    <div class="subscribe">
                        <form action="notify.php" method="post">
                            <label for="ne"><i class="fas fa-envelope"></i> <?php echo $S_SUBSCRIBE_NOTIFICATIONS; ?></label>
                            <br>
                            <input id="ne" type="email" name="s" placeholder="<?php echo $S_ENTER_EMAIL; ?>" required>
                            <button type="submit"><i class="fas fa-paper-plane"></i> <?php echo $S_SUBSCRIBE; ?></button>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php if (!$rfmon_sdr_service_active): ?>
            <div class="warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo $S_RFMON_SDR_SERVICE_INACTIVE; ?></span>
                <br>
                <span><?php echo $S_NOTIFY_ADMIN; ?></span>
            </div>
        <?php endif; ?>
        <?php if ((isset($NOTIFY_DIR) || isset($SHOW_TRANSCRIPTIONS)) && !$rfmon_watch_service_active): ?>
            <div class="warning">
                <i class="fas fa-exclamation-triangle"></i>
                <span><?php echo $S_RFMON_WATCH_SERVICE_INACTIVE; ?></span>
                <br>
                <span><?php echo $S_NOTIFY_ADMIN; ?></span>
            </div>
        <?php endif; ?>
        <?php if (isset($_SESSION['notify'])): ?>
            <div class="notify">
                <i class="fas fa-bell"></i>
                <span><?php echo $_SESSION['notify']; ?></span>
            </div>
            <?php unset($_SESSION['notify']); ?>
        <?php endif; ?>
        <div class="recordings">
            <?php foreach ($audio_records_grouped as $date => &$groups): ?>
                <div class="group-date">
                    <h2 class="gd-header">
                        <i class="fas fa-calendar-alt"></i> <?php echo date_group_name($date) ?>
                    </h2>
                    <div class="gd-list">
                        <?php foreach ($groups as &$group): ?>
                            <div class="tx-group">
                                <div class="txg-header">
                                    <i class="fas fa-clock"></i> <?php echo tx_group_name($group); ?>
                                </div>
                                <div class="txg-list">
                                    <?php foreach ($group as $record): ?>
                                        <div class="record">
                                            <div class="record-audio">
                                                <span class="time">
                                                    <i class="fas fa-volume-up"></i><?php echo $record['datetime']->format('H:i:s'); ?>
                                                </span>
                                                <audio controls>
                                                    <source src="audio.php?fn=<?php echo $record['fid']; ?>" type="audio/mpeg">
                                                    <?php echo $S_NO_AUDIO_SUPPORT; ?>
                                                </audio>
                                            </div>
                                            <?php if (isset($record['transcription'])): ?>
                                                <div class="record-transcription">
                                                    <i class="fas fa-commenting"></i> <?php echo $record['transcription']; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
            <?php if (empty($audio_records_grouped)): ?>
                <div class="gd-header">
                    <p><i class="fas fa-info-circle"></i> <?php echo $S_NO_AUDIO_RECORDINGS; ?></p>
                </div>
            <?php endif; ?>
            <br>
            <div>
                <i class="fas fa-code"></i> <?php echo $S_SOURCE_CODE; ?>:
                <a href="https://github.com/Lenart12/RFmon">GitHub</a>
                Lenart @ 2024
            </div>
        </div>
    </div>
</body>
</html>