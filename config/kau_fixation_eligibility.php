<?php

return json_decode(<<<'JSON'
{
  "study_leave": {
    "20018": {
      "name": "Saifullah Mansur",
      "first_increment_allowed": false
    },
    "20070": {
      "name": "SHEKHORE BISWAS",
      "first_increment_allowed": false
    },
    "10020": {
      "name": "Tapati Roy",
      "first_increment_allowed": false
    },
    "10018": {
      "name": "Mohammed Arif Sadik polash",
      "first_increment_allowed": false
    },
    "10019": {
      "name": "Keya Akter",
      "first_increment_allowed": false
    },
    "10012": {
      "name": "Nigar Afsana",
      "first_increment_allowed": false
    },
    "10006": {
      "name": "Sharmin Jaman",
      "first_increment_allowed": false
    },
    "10003": {
      "name": "PAPIA KHATUN",
      "first_increment_allowed": false
    },
    "10022": {
      "name": "SWARUP KUMAR KUNDU",
      "first_increment_allowed": false
    },
    "10008": {
      "name": "Jotirmoy Chakrobortty",
      "first_increment_allowed": false
    },
    "10010": {
      "name": "Newton Chandra Paul",
      "first_increment_allowed": false
    },
    "10016": {
      "name": "Nahid Hossain",
      "first_increment_allowed": false
    },
    "10017": {
      "name": "SHORIFUL Islam",
      "first_increment_allowed": false
    },
    "10005": {
      "name": "Mustasim Femous",
      "first_increment_allowed": false
    },
    "10002": {
      "name": "MD. JANNAT HOSSAIN",
      "first_increment_allowed": false
    },
    "10025": {
      "name": "Md. Amir Hossain",
      "first_increment_allowed": false
    },
    "10007": {
      "name": "Tatia Biswas",
      "first_increment_allowed": false
    },
    "10040": {
      "name": "Md. Asif Mahamud",
      "first_increment_allowed": false
    },
    "10037": {
      "name": "BIDYUT MATUBBER",
      "first_increment_allowed": true
    },
    "10041": {
      "name": "Rakibul Islam Md Rabbi",
      "first_increment_allowed": false
    },
    "10033": {
      "name": "Shabuj Kanti Nath",
      "first_increment_allowed": false
    },
    "10042": {
      "name": "Tusar Kanti Roy",
      "first_increment_allowed": true
    },
    "10064": {
      "name": "Anika Tahsin Mou",
      "first_increment_allowed": false
    },
    "10047": {
      "name": "NUSRAT JAHAN MUMU",
      "first_increment_allowed": false
    },
    "10061": {
      "name": "Kishor Kumar Tikadar",
      "first_increment_allowed": false
    },
    "10052": {
      "name": "Rabeya Akter",
      "first_increment_allowed": false
    },
    "10036": {
      "name": "Zannatul Ferdous",
      "first_increment_allowed": false
    },
    "10069": {
      "name": "Shourob Mohon Saha",
      "first_increment_allowed": false
    },
    "10056": {
      "name": "Bably Akter",
      "first_increment_allowed": true
    },
    "10086": {
      "name": "Rimu Das",
      "first_increment_allowed": true
    },
    "10105": {
      "name": "Mousumi Jahan Sumi",
      "first_increment_allowed": true
    },
    "10106": {
      "name": "PROSENJIT SARKER",
      "first_increment_allowed": false
    },
    "10107": {
      "name": "Mahfuja Ferdous",
      "first_increment_allowed": false
    }
  },
  "increment_hold": {
    "40087": "Md. Wahiduzzaman",
    "40267": "Md. Sobus Mia",
    "40029": "Salam Morol",
    "40013": "Ahmed Foysal",
    "40214": "Sheikh  Shaon",
    "40216": "Md. Kashem Ali",
    "20062": "Dip Lal Pande",
    "40139": "Hannan Akhand",
    "40221": "Paritosh Das",
    "30254": "Debashis Kumar",
    "20033": "Humaira Parvin Rumpa",
    "20038": "Chandan Kumar Sarkar"
  },
  "adhoc": {
    "20023": "001_20023_Nafisa-Jebin-Mila.pdf",
    "20090": "002_20090_Dr-Aroni-Biswash.pdf",
    "30036": "003_30036_Roman-Sheikh.pdf",
    "30043": "007_30043_Sujan-Mondal.pdf",
    "30066": "009_30066_Mst.-Mursida-Aktar.pdf",
    "30067": "008_30067_Md-golam-kibria-howlader.pdf",
    "30072": "005_30072_PRITOM-KUMAR-KUNDU.pdf",
    "30073": "004_30073_SHAIKH-JAKIA-SULTANA.pdf",
    "30075": "006_30075_Nur-Nabi-Akhter.pdf",
    "40051": "012_40051_MD.TANIJ-HOWLADER.pdf",
    "40052": "013_40052_Khursida-khatun.pdf",
    "40059": "010_40059_Minhazul-Abedin-Kazol.pdf",
    "40060": "011_40060_Shariful-Islam.pdf",
    "40061": "014_40061_Shahriar-Ahmed.pdf"
  },
  "missing_reference_reports": [
    "20018",
    "20070",
    "10020",
    "10018",
    "10019",
    "10012",
    "10006",
    "10003",
    "10022",
    "10008",
    "10010",
    "10016",
    "10017",
    "10005",
    "10002",
    "10025",
    "10007",
    "10040",
    "10037",
    "10041",
    "10033",
    "10042",
    "10064",
    "10047",
    "10061",
    "10052",
    "10036",
    "10069",
    "10056",
    "10086",
    "10105",
    "10106",
    "10107"
  ],
  "evidence": {
    "study_leave": "Education Leave.xlsx",
    "increment_hold": "Incerment Hold.xlsx",
    "adhoc": "KAU FIXATION ALL EMP.7z / ADHOC-16",
    "original_report_validation": "Study-leave PDFs absent; do not treat their results as report-verified."
  }
}
JSON, true, 512, JSON_THROW_ON_ERROR);
