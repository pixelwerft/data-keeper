<?php

/**
 * Swiss spelling: only what differs from `de` (the rest falls back to it).
 */
return [
    'Data that no longer has a purpose should go: the less a site keeps, the less can leak, and the smaller backups and the privacy policy get. For each kind of data decide how long it is kept, switch the matching cleanup rule on, and write that period into your privacy policy. Nothing here is removed by itself – a rule has to be switched on and a run confirmed.' => 'Daten ohne Zweck gehören weg: Je weniger eine Website aufbewahrt, desto weniger kann abfliessen, und desto kleiner werden Backups und Datenschutzerklärung. Für jede Art von Daten festlegen, wie lange sie bleibt, die passende Aufräumregel einschalten und die Frist in die Datenschutzerklärung schreiben. Nichts wird von selbst entfernt – eine Regel muss eingeschaltet und ein Lauf bestätigt werden.',
    'Table sizes are only read for MySQL, MariaDB and PostgreSQL.' => 'Tabellengrössen werden nur für MySQL, MariaDB und PostgreSQL gelesen.',
    'Nothing is switched on by default.' => 'Standardmässig ist nichts eingeschaltet.',
    'A key figure is getting too big' => 'Eine Kennzahl wird zu gross',
    'Many means at least (items per rule)' => 'Viel heisst mindestens (Einträge pro Regel)',
    'A tool outside the site can ask for the stored figures as JSON at the address below. Access needs the key of the site; admins find it on the «Information» tab of the overview and can replace it there.' => 'Ein Werkzeug ausserhalb der Website kann die gespeicherten Kennzahlen unter der untenstehenden Adresse als JSON abfragen. Der Zugriff braucht den Hash der Website; Admins finden ihn in der Übersicht im Tab «Infos» und können ihn dort ersetzen.',
    'Runs CHECK TABLE on every table and lists what the database reports. Nothing is changed and nothing is repaired: the database cannot repair the kind of tables Craft uses, a damaged table has to be restored from a backup. On a big database it takes a while.' => 'Führt CHECK TABLE für jede Tabelle aus und listet auf, was die Datenbank meldet. Es wird nichts geändert und nichts repariert: Die Tabellenart, die Craft verwendet, kann die Datenbank nicht reparieren; eine beschädigte Tabelle muss aus einem Backup wiederhergestellt werden. Bei einer grossen Datenbank dauert es eine Weile.',
    'Runs OPTIMIZE TABLE on every table: the table is rebuilt and unused space is given back. No data is changed. Worth it after a lot was removed. On a big database it takes a while and needs free disk space of about the size of the largest table.' => 'Führt OPTIMIZE TABLE für jede Tabelle aus: Die Tabelle wird neu aufgebaut und ungenutzter Platz freigegeben. Daten werden nicht verändert. Lohnt sich, nachdem viel entfernt wurde. Bei einer grossen Datenbank dauert es eine Weile und braucht freien Speicherplatz etwa in der Grösse der grössten Tabelle.',
    'A tool outside the site can ask for the figures of the overview as JSON – a dashboard that watches several sites, for example. Access needs the key below.' => 'Ein Werkzeug ausserhalb der Website kann die Kennzahlen der Übersicht als JSON abfragen – zum Beispiel ein Dashboard, das mehrere Websites überwacht. Der Zugriff braucht den untenstehenden Hash.',
    'Close' => 'Schliessen',
    'Database tables (by size)' => 'Datenbanktabellen (nach Grösse)',
];
