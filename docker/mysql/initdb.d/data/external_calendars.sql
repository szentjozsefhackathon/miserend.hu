-- Sample data for external_calendars table
-- Church ID 1254 with Google Calendar iCalendar URL

INSERT INTO external_calendars (church_id, name, url, active, created_at) 
VALUES 
(1254, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_qssbhpdrcj135o533mvm8d2ch4%40group.calendar.google.com/public/basic.ics', 1, NOW());
