/** Same idea as App\Support\PhoneNumber::searchKey: the digits that are the same however a number was typed. */
export function phoneKey(input) {
    return String(input ?? '')
        .replace(/\D+/g, '')
        .replace(/^(00)?251/, '')
        .replace(/^0+/, '');
}

/** True when the typed search looks like a phone number and matches this stored one. */
export function phoneMatches(stored, query) {
    const q = phoneKey(query);
    return q.length >= 3 && phoneKey(stored).includes(q);
}
