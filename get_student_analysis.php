<?php
// Legacy endpoint: the old version read tables that no longer exist. It now returns the same
// score-based analysis as ai_analysis.php so nothing can disagree with the dashboard.
include __DIR__ . '/ai_analysis.php';
