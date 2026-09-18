export const ThaiDateFormatter = {
  _months: [
    'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
    'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
  ],
  _shortMonths: [
    'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
    'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
  ],
  /**
   * Convert Gregorian Date string (YYYY-MM-DD) or JS Date object to Thai Slash format (DD/MM/YYYY พ.ศ.)
   * Example: 2026-06-13 -> 13/06/2569
   */
  toThaiSlash(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    if (isNaN(date)) return '-';

    const day = String(date.getDate()).padStart(2, '0');
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const year = date.getFullYear() + 543;

    return `${day}/${month}/${year}`;
  },

  /**
   * Convert Gregorian Date string to Thai Text format
   * Example: 2026-06-13 -> 13 มิถุนายน 2569
   */
  toThaiText(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    if (isNaN(date)) return '-';

    const day = date.getDate();
    const months = [
      'มกราคม', 'กุมภาพันธ์', 'มีนาคม', 'เมษายน', 'พฤษภาคม', 'มิถุนายน',
      'กรกฎาคม', 'สิงหาคม', 'กันยายน', 'ตุลาคม', 'พฤศจิกายน', 'ธันวาคม'
    ];
    const month = months[date.getMonth()];
    const year = date.getFullYear() + 543;

    return `${day} ${month} ${year}`;
  },

  /**
   * Format with time: 13 มิ.ย. 2569 14:30 น.
   */
  toThaiDateTime(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    if (isNaN(date)) return '-';

    const day = date.getDate();
    const shortMonths = [
      'ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.',
      'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'
    ];
    const month = shortMonths[date.getMonth()];
    const year = date.getFullYear() + 543;
    const hours = String(date.getHours()).padStart(2, '0');
    const mins = String(date.getMinutes()).padStart(2, '0');

    return `${day} ${month} ${year} ${hours}:${mins} น.`;
  },

  /**
   * Get Thai Fiscal Year (B.E.) starting from 1 Oct to 30 Sep
   * Example: Oct 2026 -> 2570 B.E., May 2026 -> 2569 B.E.
   */
  getThaiFiscalYear(dateString) {
    const date = dateString ? new Date(dateString) : new Date();
    if (isNaN(date)) return '-';

    let yearBe = date.getFullYear() + 543;
    if (date.getMonth() >= 9) { // 9 is October (0-indexed)
      yearBe += 1;
    }
    return yearBe;
  },

  /**
   * Short Thai date format: 13 มิ.ย. 2569
   */
  toThaiShortText(dateString) {
    if (!dateString) return '-';
    const date = new Date(dateString);
    if (isNaN(date)) return '-';
    const day = date.getDate();
    const month = this._shortMonths[date.getMonth()];
    const year = date.getFullYear() + 543;
    return `${day} ${month} ${year}`;
  },

  /**
   * Thai date range: 13 มิ.ย. - 15 มิ.ย. 2569
   */
  toThaiRange(startStr, endStr) {
    if (!startStr) return '-';
    const s = new Date(startStr);
    if (isNaN(s)) return '-';
    if (!endStr || startStr === endStr) return this.toThaiShortText(startStr);
    const e = new Date(endStr);
    if (isNaN(e)) return this.toThaiShortText(startStr);
    const sDay = s.getDate();
    const eDay = e.getDate();
    const sMonth = this._shortMonths[s.getMonth()];
    const eMonth = this._shortMonths[e.getMonth()];
    const sYear = s.getFullYear() + 543;
    const eYear = e.getFullYear() + 543;
    if (sYear === eYear && s.getMonth() === e.getMonth()) {
      return `${sDay} - ${eDay} ${eMonth} ${eYear}`;
    }
    if (sYear === eYear) {
      return `${sDay} ${sMonth} - ${eDay} ${eMonth} ${eYear}`;
    }
    return `${sDay} ${sMonth} ${sYear} - ${eDay} ${eMonth} ${eYear}`;
  },

  /**
   * Month and year only: มิถุนายน 2569
   */
  toMonthYear(year, monthIndex) {
    // monthIndex is 0-based
    const thaiYear = (year || new Date().getFullYear()) + 543;
    return `${this._months[monthIndex]} ${thaiYear}`;
  },

  /**
   * Thai date for table display using Date object: วันที่ 13/06/2569
   */
  toThaiDateOnly(dateStr) {
    return this.toThaiSlash(dateStr);
  },

  /**
   * Convert Arabic digits (0-9) to Thai digits (๐-๙)
   * Example: '16 กันยายน 2569' -> '๑๖ กันยายน ๒๕๖๙'
   */
  toThaiDigits(str) {
    if (str === null || str === undefined) return '';
    const thaiDigits = ['๐', '๑', '๒', '๓', '๔', '๕', '๖', '๗', '๘', '๙'];
    return String(str).replace(/[0-9]/g, (d) => thaiDigits[Number(d)]);
  }
};
