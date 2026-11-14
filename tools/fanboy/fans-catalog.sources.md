# Fanboy's fan catalogue: where each value comes from

`public/desks/fanboy/fans-catalog.json` (shipped) lists the usual PWM case fans; this file (dev-only, `tools/fanboy/`, never in
the package) names for every entry the maker's own page its values were read from, on 2026-11-14. Only the maker's page
counts — no retailer, review or wiki. A value the maker's page does not give is `null` in the catalogue, never a guess.
Brand and model names are plain text; no logos, no product pictures (the page's wheel is a generic drawing).

How the fields were read:
* `rpm_min`, `rpm_max`: the PWM speed range as the page writes it, tolerances (±10 %) left out. **Noctua**'s current
  spec pages have no «Min. rotational speed» row any more: `rpm_min` is their «Rotational speed @ 20% PWM» (they also say
  the fans stop at 0 % PWM) — except the NF-A20 PWM (its own range 350–800 rpm, it never stops). **be quiet!** and
  **Thermalright** give only the top speed: `rpm_min` null. `0` = the page says the fan can stand still (zero rpm).
* `blades`: only where the page says how many (Noctua's G2/redux/NF-F12 texts, Fractal Design, Phanteks); else null.
* `connector`: "4-pin" where the page says 4-pin or 4-pin PWM; Corsair pages that only say «PWM Control: Yes» and name
  no connector: null.
* Left out on purpose: slim and RGB-only variants, models whose page names only a maker's own daisy-chain connector
  (Fractal Momentum), and pages that were gone (Cooler Master SickleFlow 92, MasterFan MF120 Halo black).

60 entries.

| id | brand | model | mm | page | note |
|---|---|---|---|---|---|
| noctua-nf-a8-pwm | Noctua | NF-A8 PWM | 80 | https://www.noctua.at/en/products/nf-a8-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-r8-redux-1800-pwm | Noctua | NF-R8 redux-1800 PWM | 80 | https://www.noctua.at/en/products/nf-r8-redux-1800-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-a9-pwm | Noctua | NF-A9 PWM | 92 | https://www.noctua.at/en/products/nf-a9-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-b9-redux-1600-pwm | Noctua | NF-B9 redux-1600 PWM | 92 | https://www.noctua.at/en/products/nf-b9-redux-1600-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-a12x25-pwm | Noctua | NF-A12x25 PWM | 120 | https://www.noctua.at/en/products/nf-a12x25-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-a12x25-g2-pwm | Noctua | NF-A12x25 G2 PWM | 120 | https://www.noctua.at/en/products/nf-a12x25-g2-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blades from product overview text (https://www.noctua.at/en/products/nf-a12x25-g2-pwm) |
| noctua-nf-p12-redux-1700-pwm | Noctua | NF-P12 redux-1700 PWM | 120 | https://www.noctua.at/en/products/nf-p12-redux-1700-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blades from product overview text (https://www.noctua.at/en/products/nf-p12-redux-1700-pwm) |
| noctua-nf-f12-pwm | Noctua | NF-F12 PWM | 120 | https://www.noctua.at/en/products/nf-f12-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blades from product overview text (https://www.noctua.at/en/products/nf-f12-pwm) |
| noctua-nf-s12a-pwm | Noctua | NF-S12A PWM | 120 | https://www.noctua.at/en/products/nf-s12a-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-a14-pwm | Noctua | NF-A14 PWM | 140 | https://www.noctua.at/en/products/nf-a14-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blade count not stated on maker pages |
| noctua-nf-p14s-redux-1500-pwm | Noctua | NF-P14s redux-1500 PWM | 140 | https://www.noctua.at/en/products/nf-p14s-redux-1500-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blades from product overview text (https://www.noctua.at/en/products/nf-p14s-redux-1500-pwm) |
| noctua-nf-a14x25-g2-pwm | Noctua | NF-A14x25 G2 PWM | 140 | https://www.noctua.at/en/products/nf-a14x25-g2-pwm/specifications | rpm_min = 'Rotational speed @ 20% PWM' (new noctua.at spec layout has no 'Min. rotational speed' row; page also lists 0 rpm @ 0% PWM); blades from product overview text (https://www.noctua.at/en/products/nf-a14x25-g2-pwm) |
| noctua-nf-a20-pwm | Noctua | NF-A20 PWM | 200 | https://www.noctua.at/en/products/nf-a20-pwm/specifications | rpm_min 350 = the page's speed range 350-800 rpm (it also gives 350 rpm @ 0% PWM and 310 rpm @ 20% PWM: the fan does not stop); blade count not stated |
| arctic-p8-pwm-pst | Arctic | P8 PWM PST | 80 | https://www.arctic.de/en/P8-PWM-PST/ACFAN00150A | 0 rpm below 5 % PWM; blade count not stated on maker page |
| arctic-p8-max | Arctic | P8 Max | 80 | https://www.arctic.de/en/P8-Max/ACFAN00286A | 0 rpm below 5% PWM; blade count not stated on maker page |
| arctic-f8-pwm-pst | Arctic | F8 PWM PST | 80 | https://www.arctic.de/en/F8-PWM-PST/ACFAN00204A | blade count not stated on maker page |
| arctic-p9-pwm-pst | Arctic | P9 PWM PST | 92 | https://www.arctic.de/en/P9-PWM-PST-Black/ACFAN00298A | page title 'P9 PWM PST (Black)'; 0 rpm below 5 % PWM; blade count not stated on maker page |
| arctic-p12-pwm-pst | Arctic | P12 PWM PST | 120 | https://www.arctic.de/en/P12-PWM-PST/ACFAN00120A | 0 rpm below 5 % PWM; blade count not stated on maker page |
| arctic-p12-max | Arctic | P12 Max | 120 | https://www.arctic.de/en/P12-Max/ACFAN00280A | 0 rpm below 2 % PWM; blade count not stated on maker page |
| arctic-p12-pro | Arctic | P12 Pro | 120 | https://www.arctic.de/en/P12-Pro/ACFAN00305A | no product named 'P12 Pro PWM PST' on arctic.de, PWM fan is 'P12 Pro'; 0 rpm below 5 % PWM; blade count not stated on maker page |
| arctic-f12-pwm-pst | Arctic | F12 PWM PST | 120 | https://www.arctic.de/en/F12-PWM-PST/ACFAN00200A | blade count not stated on maker page |
| arctic-p14-pwm-pst | Arctic | P14 PWM PST | 140 | https://www.arctic.de/en/P14-PWM-PST/ACFAN00125A | 0 rpm below 5 % PWM; blade count not stated on maker page |
| arctic-p14-max | Arctic | P14 Max | 140 | https://www.arctic.de/en/P14-Max-Black/ACFAN00287A | page title 'P14 Max (Black)'; 0 rpm below 3 % PWM; blade count not stated on maker page |
| arctic-p14-pro | Arctic | P14 Pro | 140 | https://www.arctic.de/en/P14-Pro/ACFAN00313A | no 'P14 Pro PWM PST' on arctic.de, PWM fan is 'P14 Pro'; 0 rpm below 5 % PWM; blade count not stated on maker page |
| arctic-f14-pwm-pst | Arctic | F14 PWM PST | 140 | https://www.arctic.de/en/F14-PWM-PST/ACFAN00219A | blade count not stated on maker page |
| be-quiet-pure-wings-2-80mm-pwm | be quiet! | Pure Wings 2 80mm PWM | 80 | https://www.bequiet.com/en/casefans/619 | Legacy series page (no longer in the series menu). Only 100% PWM speed; no blade count. Article BL037. |
| be-quiet-pure-wings-2-92mm-pwm | be quiet! | Pure Wings 2 92mm PWM | 92 | https://www.bequiet.com/en/casefans/618 | Legacy series page. Only 100% PWM speed; no blade count. Article BL038 (not the 3-pin BL045). |
| be-quiet-silent-wings-4-120mm-pwm | be quiet! | Silent Wings 4 120mm PWM | 120 | https://www.bequiet.com/en/casefans/3700 | Page gives only speed at 100% PWM; no minimum, no blade count. Article BL093. |
| be-quiet-silent-wings-4-120mm-pwm-high-speed | be quiet! | Silent Wings 4 120mm PWM high-speed | 120 | https://www.bequiet.com/en/casefans/3701 | Only 100% PWM speed listed; no blade count. Article BL094. |
| be-quiet-silent-wings-pro-4-120mm-pwm | be quiet! | Silent Wings Pro 4 120mm PWM | 120 | https://www.bequiet.com/en/casefans/3705 | Only 100% PWM speed listed (fan has a speed switch; 3000 is the listed max); no blade count. Article BL098. |
| be-quiet-pure-wings-3-120mm-pwm | be quiet! | Pure Wings 3 120mm PWM | 120 | https://www.bequiet.com/en/casefans/4606 | Only 100% PWM speed listed; no blade count. Article BL105. |
| be-quiet-shadow-wings-2-120mm-pwm | be quiet! | Shadow Wings 2 120mm PWM | 120 | https://www.bequiet.com/en/casefans/1626 | Only 100% PWM speed listed; no blade count. Article BL085. No 92mm Shadow Wings 2 on bequiet.com. |
| be-quiet-light-wings-120mm-pwm | be quiet! | Light Wings 120mm PWM | 120 | https://www.bequiet.com/en/casefans/3450 | Only 100% PWM speed listed; no blade count. Article BL072. |
| be-quiet-silent-wings-4-140mm-pwm | be quiet! | Silent Wings 4 140mm PWM | 140 | https://www.bequiet.com/en/casefans/3703 | Only 100% PWM speed listed; no blade count. Article BL096. |
| be-quiet-silent-wings-4-140mm-pwm-high-speed | be quiet! | Silent Wings 4 140mm PWM high-speed | 140 | https://www.bequiet.com/en/casefans/3704 | Only 100% PWM speed listed; no blade count. Article BL097. |
| be-quiet-silent-wings-pro-4-140mm-pwm | be quiet! | Silent Wings Pro 4 140mm PWM | 140 | https://www.bequiet.com/en/casefans/3706 | Only 100% PWM speed listed (speed switch; 2400 is the listed max); no blade count. Article BL099. |
| be-quiet-pure-wings-3-140mm-pwm | be quiet! | Pure Wings 3 140mm PWM | 140 | https://www.bequiet.com/en/casefans/4609 | Only 100% PWM speed listed; no blade count. Article BL108. |
| corsair-af120-elite | Corsair | AF120 ELITE | 120 | https://www.corsair.com/us/en/p/case-fans/co-9050140-ww/af120-elite-high-performance-120mm-pwm-fluid-dynamic-bearing-fan-co-9050140-ww | Speed 400-1850 RPM. No connector field; page says page says 'PWM Control: Yes' but names no connector: null. No blade count. |
| corsair-rs120 | Corsair | RS120 | 120 | https://www.corsair.com/us/en/p/case-fans/co-9050188-ww/rs120-120mm-pwm-fan-co-9050188-ww | Speed 420-2100 RPM; page states daisy-chainable 4-pin PWM. No blade count. |
| corsair-icue-ml120-rgb-elite | Corsair | iCUE ML120 RGB ELITE | 120 | https://www.corsair.com/us/en/p/case-fans/co-9050112-ww/icue-ml120-rgb-elite-premium-120mm-pwm-magnetic-levitation-fan-single-pack-co-9050112-ww | Speed 450-2000 RPM. page says 'PWM Control: Yes' but names no connector: null. No blade count. |
| corsair-af140-elite | Corsair | AF140 ELITE | 140 | https://www.corsair.com/us/en/p/case-fans/co-9050141-ww/af140-elite-high-performance-140mm-pwm-fluid-dynamic-bearing-fan-co-9050141-ww | Speed 400-1600 RPM. page says 'PWM Control: Yes' but names no connector: null. No blade count. |
| corsair-rs140 | Corsair | RS140 | 140 | https://www.corsair.com/us/en/p/case-fans/co-9050190-ww/rs140-140mm-pwm-fan-co-9050190-ww | Speed 330-1700 RPM; page states daisy-chainable 4-pin PWM. No blade count. |
| corsair-icue-ml140-rgb-elite | Corsair | iCUE ML140 RGB ELITE | 140 | https://www.corsair.com/us/en/p/case-fans/co-9050114-ww/icue-ml140-rgb-elite-premium-140mm-pwm-magnetic-levitation-fan-single-pack-co-9050114-ww | Speed 400-1600 RPM. page says 'PWM Control: Yes' but names no connector: null. No blade count. |
| fractal-design-dynamic-3-12 | Fractal Design | Dynamic 3 - 12 | 120 | https://www.fractal-design.com/products/fans/dynamic-series/dynamic-3-12/dynamic-3-12-rgb-black/ | Page variant 'Dynamic 3 12 RGB Black'; separate 3-pin RGB lead |
| fractal-design-dynamic-3-14 | Fractal Design | Dynamic 3 - 14 | 140 | https://www.fractal-design.com/products/fans/dynamic-series/dynamic-3-14/dynamic-3-14-rgb-black/ | Page variant 'Dynamic 3 14 RGB Black'; separate 3-pin RGB lead |
| phanteks-m25g2-120 | Phanteks | M25G2-120 | 120 | https://phanteks.com/product/m25g2-120-black/ | M25 Gen2, 'Nine blades design' |
| phanteks-t30-120 | Phanteks | T30-120 | 120 | https://phanteks.com/product/t30-120/ | 30 mm thick; '0~3000 RPM' overall; switch profiles Advanced 3000 / Performance 2000 / Hybrid 1200 (0 RPM below 50% PWM); 4-pin daisy-chain |
| phanteks-d30-120 | Phanteks | D30-120 | 120 | https://phanteks.com/product/d30-120-black/ | 30 mm thick; 4-pin daisy-chain PWM plus 3-pin JST lighting |
| phanteks-m25g2-140 | Phanteks | M25G2-140 | 140 | https://phanteks.com/product/m25g2-140-black/ | M25 Gen2, 'Nine blades design' |
| thermalright-tl-b8 | Thermalright | TL-B8 | 80 | https://www.thermalright.com/product/tl-b8/ | Page gives only rated max '2200 RPM±10% (MAX)'; no min, no blade count |
| thermalright-tl-9015 | Thermalright | TL-9015 | 92 | https://www.thermalright.com/product/tl-9015/ | 92x92x15 mm slim fan; only max '2700 RPM±10% (MAX)'; no blade count |
| thermalright-tl-c12c | Thermalright | TL-C12C | 120 | https://www.thermalright.com/product/tl-c12c/ | Only max '1550 RPM±10% (MAX)'; no min, no blade count |
| thermalright-tl-b12 | Thermalright | TL-B12 | 120 | https://www.thermalright.com/product/tl-b12/ | Only max '2150 RPM±10% (MAX)'; frame 25.6 mm; no blade count |
| thermalright-tl-c14c | Thermalright | TL-C14C | 140 | https://www.thermalright.com/product/tl-c14c/ | Only max '1500 RPM±10% (MAX)'; no blade count |
| cooler-master-sickleflow-120 | Cooler Master | SickleFlow 120 | 120 | https://www.coolermaster.com/en-global/products/sickleflow-120.html | No blade count on page |
| cooler-master-mobius-120p-argb | Cooler Master | Mobius 120P ARGB | 120 | https://www.coolermaster.com/en-global/products/mobius-120p-argb.html | '0-2400 RPM' (zero-RPM capable); no blade count |
| cooler-master-mobius-140p-argb | Cooler Master | Mobius 140P ARGB | 140 | https://www.coolermaster.com/en-global/products/mobius-140p-argb.html | '0-1900 RPM' (zero-RPM capable); no blade count |
| cooler-master-sickleflow-140-argb | Cooler Master | SickleFlow 140 ARGB | 140 | https://www.coolermaster.com/en-global/products/MFX-B4DN-14NPA-R1.html | '650-1400 RPM ± 200 RPM'; no blade count |
| cooler-master-sickleflow-200-argb | Cooler Master | SickleFlow 200 ARGB | 200 | https://www.coolermaster.com/en-global/products/sickleflow-200-argb.html | '0-800 RPM (PWM)'; 200x200x25 mm; no blade count |
| silverstone-air-penetrator-184i-pro | SilverStone | Air Penetrator 184i PRO | 180 | https://www.silverstonetek.com/en/product/info/fans/AP184iPRO/ | Frame 180x180x32 mm, dual holes for 180/200 mm mounts (needs 32 mm space); model SST-AP184i-PRO; no blade count |
