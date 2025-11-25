# Finance-Dashboard

Cél

Egyszerű, gyorsan átlátható pénzügyi dashboard:
- fizetésnapi logika szerinti időszakok,
- könyvelt tranzakciók és tervezett kiadások kezelése,
- számlaegyenlegek, napi pénzmozgások és összesített egyenleg grafikon,
- kártyák és listák (legutóbbi tranzakciók, közelgő tervezettek).

Működés

Könyvelt tételek a transactions táblában (bevétel +, kiadás −, átvezetés két sor).
Tervezett tételek a planned_transactions táblában (planned/paid), a számlaegyenlegek csak a paid tételeket veszik figyelembe (nem duplázunk).
Időszakok: fizetésnap = hónap 7-e, hétvégén visszaugrik (szombat 6., vasárnap 5.). Egy időszak a következő fizetésnapig tart.
Havi összegzés: nyitó + kitapostatott bevételek − kiadások − fennmaradó (planned) → várható záró.
Hitelkeret: progress bar (sárga = felhasznált, zöld = maradék), a „Felhasználható” értéket hitelkeret nélkül számoljuk.
