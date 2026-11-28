# Third-party data

The Night Watchman's «Known vulnerabilities» (off until you switch it on) fetches public lists once a day and compares
them with the versions running on your server. This file lists each source, its licence or terms, and the notices
those terms ask for, word for word. The office ships this file in its package. The page shows the same notices in
short under «Known vulnerabilities» → «Sources».

None of these sources endorses or certifies Unraid Secretary Office. Naming them here doesn't mean they support it.

What the office keeps of each list is cut down to what the comparison needs (`data/watchman/feeds/`, root only). Of
each CVE record it keeps the affected products and versions and the publication date. Of each advisory it keeps the id,
the CVE, the severity, the version ranges and fixed versions, a summary cut to 300 characters and the publication date.
Of endoflife.date it keeps release line names, end-of-life flags and dates, and latest versions.

## CVE records (CVE Program, The MITRE Corporation)

- **Fetched from:** `https://raw.githubusercontent.com/CVEProject/cvelistV5/main/cves/…` (CVE List V5), only for
  vulnerabilities on CISA's list (see below) and the projects' advisories.
- **Terms:** CVE Program Terms of Use, <https://www.cve.org/Legal/TermsOfUse>.
- **Copyright designation:** Copyright © 1999-<current year>, The MITRE Corporation. CVE is a trademark and the CVE logo
  is a registered trademark of The MITRE Corporation. (cve.org states the end year as the current year. The page
  works it out the same way, so the year range stays correct.)

The CVE Program Terms of Use, as published at <https://www.cve.org/Legal/TermsOfUse>:

> **License**
>
> Submissions: For all materials you submit to the Common Vulnerabilities and Exposures (CVE™), you hereby grant to The
> MITRE Corporation (MITRE) and all CVE Numbering Authorities (CNAs) a perpetual, worldwide, non-exclusive, no-charge,
> royalty-free, irrevocable copyright license to reproduce, prepare derivative works of, publicly display, publicly
> perform, sublicense, and distribute such materials and derivative works. Unless required by applicable law or agreed
> to in writing, you provide such materials on an "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either
> express or implied, including, without limitation, any warranties or conditions of TITLE, NON-INFRINGEMENT,
> MERCHANTABILITY, or FITNESS FOR A PARTICULAR PURPOSE.
>
> CVE Usage: MITRE hereby grants you a perpetual, worldwide, non-exclusive, no-charge, royalty-free, irrevocable
> copyright license to reproduce, prepare derivative works of, publicly display, publicly perform, sublicense, and
> distribute Common Vulnerabilities and Exposures (CVE™). Any copy you make for such purposes is authorized provided
> that you reproduce MITRE's copyright designation and this license in any such copy.
>
> **Disclaimers**
>
> ALL DOCUMENTS AND THE INFORMATION CONTAINED THEREIN PROVIDED BY MITRE ARE PROVIDED ON AN "AS IS" BASIS AND THE
> CONTRIBUTOR, THE ORGANIZATION HE/SHE REPRESENTS OR IS SPONSORED BY (IF ANY), THE MITRE CORPORATION, ITS BOARD OF
> TRUSTEES, OFFICERS, AGENTS, AND EMPLOYEES, DISCLAIM ALL WARRANTIES, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO
> ANY WARRANTY THAT THE USE OF THE INFORMATION THEREIN WILL NOT INFRINGE ANY RIGHTS OR ANY IMPLIED WARRANTIES OF
> MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.

## NVD (NIST's National Vulnerability Database)

- **Fetched from:** `https://services.nvd.nist.gov/rest/json/cves/2.0?cveId=…` (CVE API 2.0, no API key). The office
  asks only about vulnerabilities that the CVE record can't settle, with the CVE id alone. It sends at most one request
  every 6.5 seconds (NVD's limit without a key is 5 requests in a rolling 30 seconds) and at most 40 a day. A «slow
  down» or server error ends that day's requests.
- **Terms:** NVD's terms of use, <https://nvd.nist.gov/developers/terms-of-use>. The notice it asks for, shown on the
  page wherever the results are:

> This product uses the NVD API but is not endorsed or certified by the NVD.

## CISA's Known Exploited Vulnerabilities Catalog

- **Fetched from:** `https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json`, once a day,
  only when newer (`If-Modified-Since`).
- **Licence:** CC0 1.0 Universal (public domain dedication),
  <https://creativecommons.org/publicdomain/zero/1.0/>, as CISA states for the catalog
  (<https://github.com/cisagov/kev-data/blob/develop/LICENSE>). No notice is required. CISA is named as the source only.
  The office implies no endorsement by CISA or the U.S. Department of Homeland Security and uses neither's logo or seal.
  Catalog: <https://www.cisa.gov/known-exploited-vulnerabilities-catalog>.

## GitHub Advisory Database (the projects' own security advisories)

- **Fetched from:** `https://api.github.com/repos/<owner>/<repo>/security-advisories` (GitHub's REST API, no key), only
  for the software found in a container on your server whose project publishes its advisories there. Only advisories
  rated «high» or «critical» are kept. Each advisory on the page links to its own page on github.com.
- **Licence:** the GitHub Advisory Database is licensed under the Creative Commons Attribution 4.0 International
  licence (CC BY 4.0), <https://creativecommons.org/licenses/by/4.0/>. Source: GitHub Advisory Database,
  <https://github.com/advisories> (data repository <https://github.com/github/advisory-database>). The office keeps
  each advisory cut down (see the top of this file) and shortens its summary.
- **Requests:** GitHub allows 60 requests an hour per address without a key, and a «not modified» answer counts too.
  The office sends GitHub at most 50 requests in any hour. That count covers these requests and the CVE records from
  raw.githubusercontent.com together. A «rate limit exceeded» answer (403, 429) or a last-request answer
  (x-ratelimit-remaining 0) ends that day's requests to GitHub.

## endoflife.date

- **Fetched from:** `https://endoflife.date/api/v1/products/<product>` for a fixed list of products. It tells the office
  how long a software release line gets fixes. The office keeps only release line names, end-of-life flags and dates,
  and latest versions. It never keeps or shows endoflife.date's product descriptions, which come partly from Wikipedia
  under a different licence.
- **Licence:** MIT License, <https://github.com/endoflife-date/endoflife.date/blob/master/LICENSE>.

> Copyright 2020 endoflife.date contributors
>
> Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
> documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
> rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit
> persons to whom the Software is furnished to do so, subject to the following conditions:
>
> The above copyright notice and this permission notice shall be included in all copies or substantial portions of the
> Software.
>
> THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
> WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
> COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
> OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.

## Unraid's releases: not fetched

The office never asks Unraid's servers. Lime Technology's website terms (<https://unraid.net/policies>) don't allow
automated access without written permission. Of Unraid's releases, the office reads only what Unraid's own update
check last wrote on your server (`/tmp/unraidcheck/result.json`). That check runs only if you let it in Unraid.
