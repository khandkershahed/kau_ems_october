KAU OCTOBER FIX - ONE FILE ONLY

Extract KAU_OCTOBER_FIX.zip into:
D:\Projects\KAU EMS\November\

Expected extracted CSV location:
D:\Projects\KAU EMS\November\KAU_OCTOBER_FIX\resources\kau_references\KAU_2026_10_FROZEN_413.csv

Run in your Windows CMD/Laragon terminal:

cd /d "D:\Projects\KAU EMS\November\Kau-cline"
copy /Y "D:\Projects\KAU EMS\November\KAU_OCTOBER_FIX\resources\kau_references\KAU_2026_10_FROZEN_413.csv" "resources\kau_references\KAU_2026_10_FROZEN_413.csv"
certutil -hashfile "resources\kau_references\KAU_2026_10_FROZEN_413.csv" SHA256
git status --short

Expected SHA256:
52f0ae82ac1bb65aa7357d6a827a4e7f1e4c1e33e95310f91ba7c4aa3c6b5cc6

After SHA256 matches and only CSV is modified:
git add -- resources/kau_references/KAU_2026_10_FROZEN_413.csv
git commit -m "Fix October 2026 frozen payroll reference"
git push origin main

Do NOT rename the actual application CSV. Laravel command depends on this filename.
