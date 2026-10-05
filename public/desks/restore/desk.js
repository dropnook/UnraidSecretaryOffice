/* Mr. Restori — still an apprentice with Mr. Backupsy.
   He will bring apps and VMs back from Mr. Backupsy's packages, the snapshots
   and Kopia. Until he has finished, the caretaker only shows him: he cannot be
   hired (desk.json "training"), so this page is never reached. */
(() => {
  const ID = 'restore';
  const T = (key, params) => Office.t(`${ID}.${key}`, params);
  Office.desk({
    id: ID,
    mount(root) {
      const { head } = Office.deskHead(Office.desks.get(ID), { bubble: T('training') });
      root.appendChild(head);
    },
  });
})();
