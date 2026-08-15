export interface User {
  /** A /calendar/caluser régóta visszaadja, a modellből viszont hiányzott —
   *  emiatt a javaslat beküldője sosem kapott felhasználó-azonosítót. */
  uid?: number;
  username: string;
  nickname: string;
  name: string;
  email: string;
  favorites: number[];
}
