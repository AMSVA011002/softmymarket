/* Admin product parent → subcategory selector. */
(() => {
  "use strict";

  const parent = document.querySelector("[data-category-parent]");
  const child = document.querySelector("[data-category-child]");
  if (!parent || !child) return;

  const options = Array.from(child.querySelectorAll("option[data-parent-id]"));
  const groups = Array.from(child.querySelectorAll("optgroup[data-parent-group]"));
  const placeholder = child.querySelector("option:not([data-parent-id])");
  let initialCategory = child.value;

  const applyParent = (reset) => {
    const parentId = parent.value;
    child.disabled = parentId === "";
    if (placeholder) {
      placeholder.textContent = parentId ? "Choose a subcategory or use the parent…" : "Choose a parent category first…";
    }
    options.forEach((option) => {
      option.disabled = !parentId || option.dataset.parentId !== parentId;
    });
    groups.forEach((group) => {
      group.disabled = !parentId || group.dataset.parentGroup !== parentId;
    });

    if (reset) {
      const parentOption = options.find((option) => option.dataset.parentId === parentId && option.value === parentId);
      child.value = parentOption ? parentOption.value : "";
    } else if (!options.some((option) => option.dataset.parentId === parentId && option.value === initialCategory)) {
      child.value = "";
    }
    initialCategory = "";
  };

  parent.addEventListener("change", () => applyParent(true));
  applyParent(false);
})();
