// The date a customer's usual payday suggests for the first instalment (daily-habit wave A, H6).
//
// One pure function, and the specification of the app's Dart `nextOccurrence`: the two must give the same answer for every
// input. It is tested through Node against a table of hand-computed dates (tests/Feature/App/PayPatternWebTest.php).
//
// Everything is arithmetic on whole numbers of years, months and days. No `Date` is ever made from a typed date: a date
// string read as UTC and shown in the browser's own zone is a day out in half the world, and no clock is needed here.
//
//   nextOccurrence(typed, today, day, endOfMonth) -> 'YYYY-MM-DD', or '' for no suggestion
//
//   typed        what the first-due field holds now ('' when it is empty)
//   today        the workspace's today as the server sent it, 'YYYY-MM-DD'
//   day          the customer's usual day, an integer 1 to 28 (anything else gives '')
//   endOfMonth   true when they pay at the end of the month, whatever its length (the server sends day 28 with it)
//
// The rule:
//   1. X is `typed` when it is a real calendar day written exactly YYYY-MM-DD (four, two and two ASCII digits, year 0001
//      to 9999). When it is not, X is `today` if that is one; when neither is, there is no suggestion.
//   2. At the end of the month the answer is the last day of X's month (28, 29, 30 or 31 by the month and the leap year).
//   3. Otherwise it is day `day` of X's month, unless that is before X; then it is day `day` of the next month (December
//      goes into January of the next year). X on `day` itself answers X.
//   4. A year after 9999 gives no suggestion.

const SHAPE = /^(\d{4})-(\d{2})-(\d{2})$/;

function isLeap(year) {
    return (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
}

// How many days a month has (month 1 to 12).
export function daysInMonth(year, month) {
    if (month === 2) return isLeap(year) ? 29 : 28;

    return month === 4 || month === 6 || month === 9 || month === 11 ? 30 : 31;
}

// The year, month and day of a 'YYYY-MM-DD' string that names a real day, or null.
function read(text) {
    if (typeof text !== 'string') return null;
    const match = SHAPE.exec(text);
    if (match === null) return null;
    const year = Number(match[1]);
    const month = Number(match[2]);
    const day = Number(match[3]);
    if (year < 1 || month < 1 || month > 12 || day < 1 || day > daysInMonth(year, month)) return null;

    return { year, month, day };
}

function write(year, month, day) {
    return `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
}

export function nextOccurrence(typed, today, day, endOfMonth) {
    if (!Number.isInteger(day) || day < 1 || day > 28) return '';

    const from = read(typed) ?? read(today);
    if (from === null) return '';

    if (endOfMonth) return write(from.year, from.month, daysInMonth(from.year, from.month));
    if (day >= from.day) return write(from.year, from.month, day);

    const year = from.month === 12 ? from.year + 1 : from.year;

    return year > 9999 ? '' : write(year, from.month === 12 ? 1 : from.month + 1, day);
}
