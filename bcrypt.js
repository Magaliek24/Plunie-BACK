const bcrypt = require("bcrypt");

async function generateHash() {
  const hash = await bcrypt.hash("Magalie12345", 10);
  console.log(hash);
}

generateHash();
