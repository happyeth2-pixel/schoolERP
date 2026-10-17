// One place for "which greeting applies right now" -- used by HomeTasks.jsx and TeacherHome.jsx, which used
// to each compute this themselves from `new Date().getHours()` (the BROWSER's clock, not the school's own
// business timezone -- a device set to a different timezone showed the wrong time-of-day greeting). The
// hour must come from the server-shared `today.hour` prop (see HandleInertiaRequests), never the browser.
export function greetingKey(hour) {
    if (hour < 12) return 'home.greeting_morning';
    if (hour < 18) return 'home.greeting_afternoon';
    return 'home.greeting_evening';
}
