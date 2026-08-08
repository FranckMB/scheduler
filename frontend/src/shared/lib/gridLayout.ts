/**
 * Placement en couloirs des blocs qui se chevauchent dans une même colonne — foyer unique
 * (D-27).
 *
 * ⚑ Cette fonction existait DEUX fois, recopiée caractère pour caractère (mêmes noms de
 * variables, même `flush`, même `clusterEnd = -1`) : une fois pour la grille du planning, une
 * fois pour celle du week-end. Corriger un défaut de chevauchement sur l'une laissait l'autre —
 * deux matchs se recouvrant sur un écran et rangés côte à côte sur l'autre.
 *
 * Elle est générique sur la forme MINIMALE qu'elle manipule : chaque grille garde son propre
 * type de cellule et son propre constructeur de lignes.
 */
export interface LaneCell {
  gridColumn: number;
  lane?: number;
  laneCount?: number;
}

export interface LaneInterval<C extends LaneCell> {
  startMin: number;
  endMin: number;
  cell: C;
}

export function assignLanes<C extends LaneCell>(intervals: LaneInterval<C>[]): void {
  const byColumn = new Map<number, LaneInterval<C>[]>();
  for (const interval of intervals) {
    const list = byColumn.get(interval.cell.gridColumn) ?? [];
    list.push(interval);
    byColumn.set(interval.cell.gridColumn, list);
  }

  for (const list of byColumn.values()) {
    list.sort((a, b) => a.startMin - b.startMin || a.endMin - b.endMin);

    let cluster: LaneInterval<C>[] = [];
    let clusterEnd = -1;
    const flush = (): void => {
      const laneEnds: number[] = [];
      for (const item of cluster) {
        let lane = laneEnds.findIndex((end) => end <= item.startMin);
        if (-1 === lane) {
          lane = laneEnds.length;
        }
        laneEnds[lane] = item.endMin;
        item.cell.lane = lane;
      }
      for (const item of cluster) {
        item.cell.laneCount = laneEnds.length;
      }
    };

    for (const interval of list) {
      if (cluster.length > 0 && interval.startMin >= clusterEnd) {
        flush();
        cluster = [];
        clusterEnd = -1;
      }
      cluster.push(interval);
      clusterEnd = Math.max(clusterEnd, interval.endMin);
    }
    if (cluster.length > 0) {
      flush();
    }
  }
}
