
LOCK TABLES `external_calendars` WRITE;
/*!40000 ALTER TABLE `external_calendars` DISABLE KEYS */;
set autocommit=0;

INSERT INTO external_calendars (church_id, name, url, active, created_at) 
VALUES 
(1254, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_qssbhpdrcj135o533mvm8d2ch4%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5438, 'Google Calendar', 'https://calendar.google.com/calendar/ical/df25b603874c2efa48c0fe0f0216189648c8969ba48f588d6fbdbbcd3fa26303%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5439, 'Google Calendar', 'https://calendar.google.com/calendar/ical/d5c8bf2349aaf600dc28e2c56d21b2afe93fb05a18415e8ffa0ba0f34f5d5129%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5440, 'Google Calendar', 'https://calendar.google.com/calendar/ical/a1b75c2a371e814fb3f5e27a6c633f90a27fe08b5eab3227523388f8bf30d4fd%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5441, 'Google Calendar', 'https://calendar.google.com/calendar/ical/746c7fcfa7c2ccf5b339bdc8aa2fd762f881bc325942f305a590ed2dcff21d21%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5442, 'Google Calendar', 'https://calendar.google.com/calendar/ical/01de5cc5a0981ab267668ff5c58faef8486ea5e77ac035dc8949a272e820f165%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5443, 'Google Calendar', 'https://calendar.google.com/calendar/ical/f59374a784cad93fca11dea1ef57686848ce186746a0a47d92996c9a7040c0b8%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5444, 'Google Calendar', 'https://calendar.google.com/calendar/ical/46d4a2f1b09c1c71223edbcb1fecac95560ea475cbbcad82d588e9b8b841765b%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5445, 'Google Calendar', 'https://calendar.google.com/calendar/ical/0f0b395cf590ab8f71af00ddc3b2a98be3b5f2e6d0780d38c7764b419d516685%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5446, 'Google Calendar', 'https://calendar.google.com/calendar/ical/5d2c94c3cb8a3ad87330e8ba5bcbafb2bff9b6360048d86d4c442b7a19421a65%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(5447, 'Google Calendar', 'https://calendar.google.com/calendar/ical/3d5aca53a8a330ef59ea1e8d424789df909fc14ac0eb5af86e2d0bb8dccaa007%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(281, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_2948f87c7b4462c7edb9ed1527825c0bf649cfeb883382d5f3bf4c98259cea6f%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(4432, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_2c626b51218990c262b99d0c9f5e4c462d44f0a02dc27a47ee01d56426dc70ae%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(280, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_55fd189db691235812bd3e9ff6bb5221b9bd90e923ba4532ef39dbc6ab5588e7%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(276, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_ba7bcb264e26fc15729607d52fcb818d987b936bcbb5463cb444a0bc5ac394b1%40group.calendar.google.com/public/basic.ics', 1, NOW()),
(282, 'Google Calendar', 'https://calendar.google.com/calendar/ical/c_e9558bb09ffceb0137e1d65f0bed3b53a6ec5b9c233eeb0c5aefd5fdba8cad00%40group.calendar.google.com/public/basic.ics', 1, NOW());

/*!40000 ALTER TABLE `external_calendars` ENABLE KEYS */;
UNLOCK TABLES;
commit;
