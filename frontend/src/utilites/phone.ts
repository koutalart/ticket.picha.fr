const GROUPINGS: Record<string, number[]> = {
    '262': [3, 2, 2, 2],
    '33': [1, 2, 2, 2, 2],
    '261': [2, 2, 3, 2],
    '45': [2, 2, 2, 2],
};

export const formatPhone = (phone?: string | null): string => {
    if (!phone) {
        return '—';
    }

    const match = Object.keys(GROUPINGS)
        .sort((a, b) => b.length - a.length)
        .find(code => phone.startsWith(`+${code}`));

    if (!match) {
        return phone;
    }

    let rest = phone.slice(match.length + 1);
    const parts: string[] = [];
    for (const size of GROUPINGS[match]) {
        if (!rest) {
            break;
        }
        parts.push(rest.slice(0, size));
        rest = rest.slice(size);
    }
    if (rest) {
        parts.push(rest);
    }

    return `+${match} ${parts.join(' ')}`;
};
