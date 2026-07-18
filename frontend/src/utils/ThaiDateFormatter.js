export const ThaiDateFormatter = {
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
   * Convert Gregorian Date string (YYYY-MM-DD) to Thai Text format
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
  }
};
