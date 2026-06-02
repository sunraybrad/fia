<?
function generateTimeOptions24($selectedTime = '') {
    $times = [];
    for ($i = 0; $i < 24 * 60; $i += 15) {
        $hour = floor($i / 60);
        $minute = $i % 60;
        $time = sprintf('%02d:%02d', $hour, $minute);
        $selected = ($time == $selectedTime) ? 'selected' : '';
        $times[] = "<option value=\"$time\" $selected>$time</option>";
    }
    return implode("\n", $times);
}
function generateTimeOptions12($selectedTime = '') {
    $times = [];
    for ($i = 0; $i < 24 * 60; $i += 15) {
        $hour = floor($i / 60);
        $minute = $i % 60;
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        $hour12 = $hour12 ? $hour12 : 12; // the hour '0' should be '12'
        $time = sprintf('%02d:%02d %s', $hour12, $minute, $ampm);
        $selected = ($time == $selectedTime) ? 'selected' : '';
        $times[] = "<option value=\"$time\" $selected>$time</option>";
    }
    return implode("\n", $times);
}
function generateTimeOptions($selectedTime = '') {
    $times = [];
    $options = "";
    for ($i = 0; $i < 24 * 60; $i += 15) {
        $hour = floor($i / 60);
        $minute = $i % 60;
        $ampm = $hour >= 12 ? 'PM' : 'AM';
        $hour12 = $hour % 12;
        $hour12 = $hour12 ? $hour12 : 12; // the hour '0' should be '12'
        $time = sprintf('%02d:%02d %s', $hour12, $minute, $ampm);
        $times[] = $time;
        $selected = ($time == $selectedTime) ? 'selected' : '';
        $options .= "<option value=\"$time\" $selected>$time</option>";
    }
    return [$times, $options];
}
?>